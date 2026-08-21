<?php
/**
 * One capped, non autoloaded option used as a diagnostic breadcrumb.
 *
 * Two things in this plugin need to record what they threw away: the placeholder
 * guard records translations it rejected string by string, and the response
 * normalizer records chunks the engine had to discard whole. Both are the same
 * mechanism, a ring buffer in an option, and both matter for the same reason.
 * The 2026-08-14 loss of 200 strings was invisible for three days because the
 * only place it surfaced was one of these logs that nobody was reading.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Breadcrumb {

    /**
     * Append entries to a capped log option.
     *
     * @param string $option  Option name.
     * @param array  $entries List of entries to append.
     * @param int    $cap     Maximum entries to keep.
     *
     * @return void
     */
    public static function append( $option, $entries, $cap ) {
        if ( $entries === array() ) {
            return;
        }

        $log = get_option( $option, array() );

        if ( ! is_array( $log ) ) {
            $log = array();
        }

        foreach ( $entries as $entry ) {
            $log[] = $entry;
        }

        if ( count( $log ) > $cap ) {
            $log = array_slice( $log, -$cap );
        }

        update_option( $option, $log, false );
    }

    /**
     * Truncate a value for storage in a breadcrumb.
     *
     * @param mixed $value  Value to store.
     * @param int   $length Maximum characters.
     *
     * @return string
     */
    public static function excerpt( $value, $length = 300 ) {
        return mb_substr( (string) $value, 0, $length );
    }
}
