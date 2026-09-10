<?php
/** Database-backed state shared by PHP workers; never use the object cache for leases. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Storage {
    const VERSION = '1';
    private static $ready = array();

    public static function table( $suffix ) {
        global $wpdb;
        return $wpdb->prefix . 'trp_llm_' . $suffix;
    }

    /** Install on upgrade as well as activation, and separately for each multisite blog. */
    public static function ensure() {
        global $wpdb;
        $table = self::table( 'state' );
        if ( isset( self::$ready[ $table ] ) ) {
            return self::$ready[ $table ];
        }
        if ( self::VERSION === get_option( 'trp_llm_schema_version' ) ) {
            return self::$ready[ $table ] = true;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            fingerprint char(64) NOT NULL,
            owner char(32) NOT NULL DEFAULT '',
            lease_until bigint unsigned NOT NULL DEFAULT 0,
            failures int unsigned NOT NULL DEFAULT 0,
            retry_at bigint unsigned NOT NULL DEFAULT 0,
            result longtext NULL,
            result_until bigint unsigned NOT NULL DEFAULT 0,
            expires_at bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (fingerprint),
            KEY expires_at (expires_at)
        ) {$collation};" );
        $costs = self::table( 'costs' );
        dbDelta( "CREATE TABLE {$costs} (
            day date NOT NULL,
            engine varchar(32) NOT NULL,
            coverage varchar(16) NOT NULL,
            cost_usd decimal(24,12) NOT NULL DEFAULT 0,
            responses bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (day,engine,coverage)
        ) {$collation};" );
        foreach ( array( $table, $costs ) as $name ) {
            if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ) {
                self::error( 'schema' );
                return self::$ready[ $table ] = false;
            }
        }
        update_option( 'trp_llm_schema_version', self::VERSION, false );
        return self::$ready[ $table ] = true;
    }

    public static function error( $operation ) {
        // Do not log SQL, credentials, or source text from $wpdb->last_error.
        do_action( 'trp_llm_storage_error', $operation );
        if ( TRP_LLM_Engine_Cooldown::note_skip( 'storage:' . $operation ) ) {
            TRP_LLM_Breadcrumb::append( 'trp_llm_http_failures', array( array(
                'time' => time(), 'reason' => 'storage-' . $operation,
            ) ), 50 );
        }
    }

    public static function cleanup() {
        global $wpdb;
        if ( self::ensure() ) {
            $state = self::table( 'state' );
            // Never delete a live lease, even if its original retention time passed.
            $wpdb->query( "DELETE FROM {$state} WHERE expires_at <= UNIX_TIMESTAMP() AND lease_until <= UNIX_TIMESTAMP() LIMIT 5000" );
            $days = max( 1, min( 3650, (int) apply_filters( 'trp_llm_cost_retention_days', 90 ) ) );
            $costs = self::table( 'costs' );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$costs} WHERE day < %s", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) );
        }
        TRP_LLM_Breadcrumb::prune();
    }
}
