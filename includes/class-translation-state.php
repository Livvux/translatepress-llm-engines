<?php
/** Per-string failure backoff, atomic owner leases, and short successful-result handoff. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Translation_State {
    const POLICY_VERSION = '1';

    /**
     * Hash the effective single-string request, not the batch membership.
     * Prompt/body filters must be deterministic. Sites modifying HTTP arguments
     * or external glossary state must bump trp_llm_configuration_version.
     * Neither the source nor the API key is stored in this identifier.
     */
    public static function fingerprint( $source, array $context, array $settings ) {
        $context['strings'] = array( $source );
        $context['attempt'] = 0;
        $body = TRP_LLM_Request_Shape::body( $context );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $identity = array(
            'version' => apply_filters( 'trp_llm_configuration_version', self::POLICY_VERSION, $context ),
            'engine' => $context['engine'],
            'source' => $source,
            'source_locale' => $context['source_language_code'],
            'target_locale' => $context['target_language_code'],
            'body' => $body,
            'credential' => hash_hmac( 'sha256', (string) ( $settings[ $context['engine'] . '-api-key' ] ?? '' ), wp_salt( 'auth' ) ),
        );
        $json = wp_json_encode( self::canonical( $identity ) );
        return false === $json
            ? new WP_Error( 'trp_llm_invalid_identity', 'Unable to encode translation configuration.' )
            : hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
    }

    private static function canonical( $value ) {
        if ( is_array( $value ) ) {
            if ( ! array_is_list( $value ) ) {
                ksort( $value );
            }
            $value = array_map( array( __CLASS__, 'canonical' ), $value );
        }
        return $value;
    }

    public static function lease_seconds() {
        // Renew before EACH HTTP attempt. The lease must outlive its timeout.
        return max( TRP_LLM_Request_Retry::request_timeout() + 30, (int) apply_filters( 'trp_llm_lease_seconds', 120 ) );
    }

    public static function backoff_seconds( $failures ) {
        $base = max( 1, (int) apply_filters( 'trp_llm_content_backoff_base', 60 ) );
        $cap = max( $base, (int) apply_filters( 'trp_llm_content_backoff_max', DAY_IN_SECONDS ) );
        $seconds = min( $cap, $base * pow( 2, min( 20, max( 0, $failures - 1 ) ) ) );
        // Positive jitter spreads retries without ever shortening the minimum.
        $seconds = min( $cap, $seconds + random_int( 0, max( 0, (int) ( $seconds / 10 ) ) ) );
        return max( 1, (int) apply_filters( 'trp_llm_content_backoff_seconds', $seconds, $failures ) );
    }

    /** Returns acquired/cached/backoff/locked/error. All arbitration uses SQL's clock. */
    public static function claim( $fingerprint ) {
        global $wpdb;
        if ( ! TRP_LLM_Storage::ensure() ) {
            return array( 'status' => 'error' );
        }
        $table = TRP_LLM_Storage::table( 'state' );
        $owner = bin2hex( random_bytes( 16 ) );
        $ttl = self::lease_seconds();
        $insert = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (fingerprint,expires_at) VALUES (%s,UNIX_TIMESTAMP()+%d)", $fingerprint, $ttl
        ) );
        if ( false === $insert ) {
            TRP_LLM_Storage::error( 'claim' );
            return array( 'status' => 'error' );
        }
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET owner=%s, lease_until=UNIX_TIMESTAMP()+%d, result=NULL, result_until=0,
                expires_at=GREATEST(expires_at,UNIX_TIMESTAMP()+%d)
             WHERE fingerprint=%s AND lease_until<=UNIX_TIMESTAMP() AND retry_at<=UNIX_TIMESTAMP()
                AND (result IS NULL OR result_until<=UNIX_TIMESTAMP())", $owner, $ttl, $ttl, $fingerprint
        ) );
        if ( false === $claimed ) {
            TRP_LLM_Storage::error( 'claim' );
            return array( 'status' => 'error' );
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT *,UNIX_TIMESTAMP() AS clock FROM {$table} WHERE fingerprint=%s", $fingerprint
        ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            TRP_LLM_Storage::error( 'read-state' );
            return array( 'status' => 'error' );
        }
        if ( 1 === $claimed && hash_equals( $owner, $row['owner'] ) ) {
            return array( 'status' => 'acquired', 'owner' => $owner, 'failures' => (int) $row['failures'] );
        }
        if ( null !== $row['result'] && $row['result_until'] > $row['clock'] ) {
            return array( 'status' => 'cached', 'result' => $row['result'] );
        }
        return array( 'status' => $row['retry_at'] > $row['clock'] ? 'backoff' : 'locked' );
    }

    public static function renew( $fingerprint, $owner ) {
        global $wpdb;
        $table = TRP_LLM_Storage::table( 'state' );
        // +1 guarantees a changed row even for two renewals in the same second.
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET lease_until=GREATEST(lease_until,UNIX_TIMESTAMP()+%d)+1
             WHERE fingerprint=%s AND owner=%s AND lease_until>UNIX_TIMESTAMP()", self::lease_seconds(), $fingerprint, $owner
        ) );
    }

    public static function release( $fingerprint, $owner ) {
        global $wpdb;
        $table = TRP_LLM_Storage::table( 'state' );
        // A stale worker must not clear a successor's lease.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET owner='',lease_until=0 WHERE fingerprint=%s AND owner=%s", $fingerprint, $owner
        ) );
    }

    public static function finish( $fingerprint, array $claim, $translation, $failed ) {
        global $wpdb;
        $table = TRP_LLM_Storage::table( 'state' );
        if ( is_string( $translation ) ) {
            // Bridges the gap between returning from the engine and TP's dictionary write.
            $ttl = max( 1, min( 300, (int) apply_filters( 'trp_llm_result_handoff_seconds', 60 ) ) );
            $sql = $wpdb->prepare(
                "UPDATE {$table} SET result=%s,result_until=UNIX_TIMESTAMP()+%d,failures=0,retry_at=0,
                    expires_at=UNIX_TIMESTAMP()+%d,owner='',lease_until=0
                 WHERE fingerprint=%s AND owner=%s AND lease_until>UNIX_TIMESTAMP()",
                $translation, $ttl, $ttl, $fingerprint, $claim['owner']
            );
        } elseif ( $failed ) {
            $delay = self::backoff_seconds( $claim['failures'] + 1 );
            $retention = max( $delay, (int) apply_filters( 'trp_llm_state_retention_seconds', 7 * DAY_IN_SECONDS ) );
            $sql = $wpdb->prepare(
                "UPDATE {$table} SET failures=LEAST(failures+1,100),retry_at=UNIX_TIMESTAMP()+%d,
                    expires_at=UNIX_TIMESTAMP()+%d,owner='',lease_until=0
                 WHERE fingerprint=%s AND owner=%s AND lease_until>UNIX_TIMESTAMP()",
                $delay, $retention, $fingerprint, $claim['owner']
            );
        } else {
            return;
        }
        if ( false === $wpdb->query( $sql ) ) {
            TRP_LLM_Storage::error( 'finish' );
        }
    }

    /** The worker returns translations plus keys with an observed paid content failure. */
    public static function run( array $chunk, array $context, array $settings, $sender, $worker ) {
        $send = $claims = $keys = $groups = $ready = array();
        try {
            foreach ( $chunk as $key => $source ) {
                $id = self::fingerprint( $source, $context, $settings );
                if ( is_wp_error( $id ) ) {
                    self::note( $context['engine'], $id->get_error_code() );
                    continue;
                }
                if ( isset( $groups[ $id ] ) ) {
                    $groups[ $id ][] = $key;
                    continue;
                }
                $groups[ $id ] = array( $key );
                $claim = self::claim( $id );
                if ( 'cached' === $claim['status'] ) {
                    if ( TRP_LLM_Placeholder_Guard::is_safe( $source, $claim['result'] ) ) {
                        $ready[ $id ] = $claim['result'];
                    }
                } elseif ( 'acquired' === $claim['status'] ) {
                    $claims[ $id ] = $claim;
                    $keys[ $key ] = $id;
                    $send[ $key ] = $source;
                } else {
                    self::note( $context['engine'], $claim['status'] );
                }
            }
            if ( $send ) {
                $guarded_sender = static function ( $part, $attempt ) use ( $sender, $keys, $claims ) {
                    foreach ( $part as $key => $source ) {
                        $id = $keys[ $key ];
                        if ( ! self::renew( $id, $claims[ $id ]['owner'] ) ) {
                            return new WP_Error( 'trp_llm_lease_lost', 'Translation lease is no longer owned.' );
                        }
                    }
                    return call_user_func( $sender, $part, $attempt );
                };
                $outcome = call_user_func( $worker, $send, $guarded_sender );
                foreach ( $send as $key => $source ) {
                    $id = $keys[ $key ];
                    $translation = $outcome['translations'][ $key ] ?? null;
                    self::finish( $id, $claims[ $id ], $translation, isset( $outcome['failed'][ $key ] ) );
                    if ( is_string( $translation ) ) {
                        $ready[ $id ] = $translation;
                    }
                }
            }
        } finally {
            foreach ( $claims as $id => $claim ) {
                self::release( $id, $claim['owner'] );
            }
        }
        $result = array();
        foreach ( $ready as $id => $translation ) {
            foreach ( $groups[ $id ] as $key ) {
                $result[ $key ] = $translation;
            }
        }
        return $result;
    }

    private static function note( $engine, $reason ) {
        if ( TRP_LLM_Engine_Cooldown::note_skip( $engine . ':state:' . $reason ) ) {
            TRP_LLM_Response_Normalizer::log_chunk_failure( $engine, 'state-' . $reason, 0, 0, '' );
        }
    }
}
