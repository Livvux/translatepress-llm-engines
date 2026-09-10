<?php
/** Bounded, age-limited diagnostic metadata. Source/response content is opt-in. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Breadcrumb {
    const OPTIONS = array(
        'trp_llm_http_failures' => 50,
        'trp_llm_chunk_failures' => 50,
        'trp_llm_placeholder_rejections' => 200,
    );
    const CONTENT_FIELDS = array( 'source', 'translation', 'sample', 'detail' );

    public static function append( $option, $entries, $cap ) {
        if ( ! isset( self::OPTIONS[ $option ] ) ) {
            return;
        }
        $log = get_option( $option, array() );
        $log = array_merge( is_array( $log ) ? $log : array(), (array) $entries );
        update_option( $option, self::clean( $log, min( self::OPTIONS[ $option ], max( 1, (int) $cap ) ) ), false );
    }

    /** Also runs on upgrade and hourly so inactive logs do not retain old snippets. */
    public static function prune() {
        foreach ( self::OPTIONS as $option => $cap ) {
            $log = get_option( $option, array() );
            if ( $log ) {
                update_option( $option, self::clean( is_array( $log ) ? $log : array(), $cap ), false );
            }
        }
    }

    private static function clean( array $entries, $cap ) {
        if ( ! apply_filters( 'trp_llm_diagnostics_enabled', true ) ) {
            return array();
        }
        $retention = max( 0, (int) apply_filters( 'trp_llm_diagnostic_retention_seconds', 7 * DAY_IN_SECONDS ) );
        if ( 0 === $retention ) {
            return array();
        }
        $after = time() - $retention;
        $clean = array();
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['time'] ) || (int) $entry['time'] < $after ) {
                continue;
            }
            $row = array( 'time' => (int) $entry['time'] );
            foreach ( array( 'engine', 'model', 'target', 'reason' ) as $field ) {
                if ( isset( $entry[ $field ] ) && is_scalar( $entry[ $field ] ) ) {
                    $row[ $field ] = self::redact( (string) $entry[ $field ], 120 );
                }
            }
            foreach ( array( 'expected', 'received', 'http' ) as $field ) {
                if ( isset( $entry[ $field ] ) ) {
                    $row[ $field ] = (int) $entry[ $field ];
                }
            }
            foreach ( self::CONTENT_FIELDS as $field ) {
                if ( isset( $entry[ $field ] ) ) {
                    $row[ $field ] = self::excerpt( $entry[ $field ] );
                }
            }
            $clean[] = $row;
        }
        return array_slice( $clean, -$cap );
    }

    public static function excerpt( $value, $length = 300 ) {
        if ( ! is_scalar( $value ) || '' === (string) $value ) {
            return '';
        }
        if ( ! apply_filters( 'trp_llm_diagnostic_content', false ) ) {
            return '[redacted]';
        }
        return self::redact( (string) $value, $length );
    }

    /** Defense in depth for opted-in snippets, not a general anonymization guarantee. */
    private static function redact( $value, $length ) {
        $settings = get_option( 'trp_machine_translation_settings', array() );
        foreach ( array( 'openai', 'anthropic', 'openrouter', 'deepseek' ) as $engine ) {
            $secret = is_array( $settings ) ? ( $settings[ $engine . '-api-key' ] ?? '' ) : '';
            if ( is_string( $secret ) && '' !== $secret ) {
                $value = str_replace( $secret, '[redacted]', $value );
            }
        }
        foreach ( array(
            '/\\bBearer\\s+[A-Za-z0-9._~+\\/=-]+/i',
            '/\\bsk-[A-Za-z0-9_-]+/',
            '/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i',
            '/(?<=[?&])(?:api[_-]?key|access[_-]?token|token|password|secret|key)=[^&\\s"<>]*/i',
        ) as $pattern ) {
            $value = preg_replace( $pattern, '[redacted]', $value );
        }
        $value = (string) apply_filters( 'trp_llm_redact_diagnostic', $value );
        $length = max( 0, min( 1000, (int) $length ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
    }
}
