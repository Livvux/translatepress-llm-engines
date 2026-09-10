<?php
/** Atomic daily counters; reported amounts and unknown costs must not be conflated. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Cost_Ledger {
    /** Count every HTTP-success response, including malformed or rejected content. */
    public static function record( $engine, $body ) {
        global $wpdb;
        if ( ! TRP_LLM_Storage::ensure() ) {
            return false;
        }
        $cost = is_array( $body ) ? ( $body['usage']['cost'] ?? null ) : null;
        $known = TRP_LLM_Model_Catalog::valid_price( $cost ) && (float) $cost < 1.0e10;
        $table = TRP_LLM_Storage::table( 'costs' );
        // Decimal arithmetic happens in SQL; no read/modify/write option race.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (day,engine,coverage,cost_usd,responses) VALUES (%s,%s,%s,%s,1)
             ON DUPLICATE KEY UPDATE cost_usd=cost_usd+VALUES(cost_usd),responses=responses+1",
            gmdate( 'Y-m-d' ), $engine, $known ? 'reported' : 'unknown', $known ? sprintf( '%.12F', $cost ) : '0'
        ) );
        if ( false === $result ) {
            TRP_LLM_Storage::error( 'cost' );
        }
        return false !== $result;
    }

    /** Copy the old approximate totals once. INSERT IGNORE makes retries idempotent. */
    public static function migrate_legacy() {
        global $wpdb;
        if ( ! TRP_LLM_Storage::ensure() || get_option( 'trp_llm_cost_migrated' ) ) {
            return;
        }
        // Read the physical option, bypassing the compatibility read filter below.
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'trp_llm_cost_daily' ) );
        $legacy = maybe_unserialize( $raw );
        $table = TRP_LLM_Storage::table( 'costs' );
        foreach ( is_array( $legacy ) ? $legacy : array() as $key => $cost ) {
            if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})\|(openai|anthropic|openrouter|deepseek)$/D', $key, $match )
                || ! TRP_LLM_Model_Catalog::valid_price( $cost ) || (float) $cost >= 1.0e10 ) {
                continue;
            }
            list( $year, $month, $day ) = array_map( 'intval', explode( '-', $match[1] ) );
            if ( ! checkdate( $month, $day, $year ) ) {
                continue;
            }
            if ( false === $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (day,engine,coverage,cost_usd,responses) VALUES (%s,%s,'legacy',%s,0)",
                $match[1], $match[2], sprintf( '%.12F', $cost )
            ) ) ) {
                TRP_LLM_Storage::error( 'cost-migration' );
                return;
            }
        }
        update_option( 'trp_llm_cost_migrated', '1', false );
    }

    /** Rows retain coverage and response counts; unknown costs are NOT zero-cost responses. */
    public static function daily() {
        global $wpdb;
        if ( ! TRP_LLM_Storage::ensure() ) {
            return array();
        }
        self::migrate_legacy();
        $table = TRP_LLM_Storage::table( 'costs' );
        $days = max( 1, min( 3650, (int) apply_filters( 'trp_llm_cost_retention_days', 90 ) ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT day,engine,coverage,cost_usd,responses FROM {$table} WHERE day >= %s ORDER BY day,engine,coverage",
            gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS )
        ), ARRAY_A ) ?: array();
    }

    /** Read-only compatibility view; no more writes to the old shared option. */
    public static function legacy_totals( $pre ) {
        if ( false !== $pre ) {
            return $pre;
        }
        $totals = array();
        foreach ( self::daily() as $row ) {
            if ( 'unknown' !== $row['coverage'] ) {
                $key = $row['day'] . '|' . $row['engine'];
                $totals[ $key ] = ( $totals[ $key ] ?? 0 ) + (float) $row['cost_usd'];
            }
        }
        return $totals;
    }
}
