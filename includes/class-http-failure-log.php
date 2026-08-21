<?php
/**
 * Record the API calls that never produced a usable response at all.
 *
 * The engines used to enter their logging branch only on HTTP 200. Everything
 * else fell through an if statement with no else branch: an expired key, a
 * spent balance, a rate limit, a gateway error, a connection timeout. None of
 * them left a trace anywhere, because the vendor's own machine translation log
 * is a setting that is off on this site.
 *
 * The result is that a completely dead engine and a perfectly idle site look
 * identical from the outside. Every diagnostic starts by asking which of the
 * two it is, and until now nothing in the system could answer that.
 *
 * This is a second ring buffer rather than more entries in the existing
 * trp_llm_chunk_failures option, and that separation is deliberate. Measured on
 * production on 2026-08-17, 44 of the 50 slots in that option were taken by one
 * loud content failure repeating in a three minute window, which had already
 * pushed out the four truncations that were the interesting part. Transport
 * failures and content failures must not compete for the same 50 slots, because
 * the loud one always wins and the rare one is what a diagnosis needs.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Http_Failure_Log {

    /**
     * Option holding the ring buffer.
     */
    const OPTION = 'trp_llm_http_failures';

    /**
     * Entries kept. Matches the cap on trp_llm_chunk_failures.
     */
    const CAP = 50;

    /**
     * Classify what came back from wp_remote_post().
     *
     * Returns 'ok' for a 200, otherwise a short machine readable reason. The
     * codes that carry their own diagnosis get their own reason so a glance at
     * the log answers the question without opening an entry: 401 is the key,
     * 402 is the balance, 429 is the rate limit.
     *
     * @param mixed $response Return value of wp_remote_post().
     *
     * @return string
     */
    public static function reason_for( $response ) {
        if ( is_wp_error( $response ) ) {
            return 'wp-error';
        }

        if ( ! is_array( $response ) || ! isset( $response['response']['code'] ) ) {
            return 'malformed-response';
        }

        $code = (int) $response['response']['code'];

        if ( 200 === $code ) {
            return 'ok';
        }

        if ( in_array( $code, array( 401, 402, 429 ), true ) ) {
            return 'http-' . $code;
        }

        if ( $code >= 500 ) {
            return 'http-5xx';
        }

        if ( $code >= 400 ) {
            return 'http-4xx';
        }

        return 'http-' . $code;
    }

    /**
     * Append one failed call, or do nothing when the call succeeded.
     *
     * @param string $engine   Engine slug.
     * @param string $model    Model the request asked for.
     * @param string $target   Target language of the request.
     * @param mixed  $response Return value of wp_remote_post().
     *
     * @return string The reason that was recorded, or 'ok' when nothing was.
     */
    public static function record( $engine, $model, $target, $response ) {
        $reason = self::reason_for( $response );

        if ( 'ok' === $reason ) {
            return $reason;
        }

        TRP_LLM_Breadcrumb::append(
            self::OPTION,
            array(
                array(
                    'engine' => (string) $engine,
                    'model'  => (string) $model,
                    'target' => (string) $target,
                    'time'   => time(),
                    'reason' => $reason,
                    'http'   => self::status_code( $response ),
                    'detail' => TRP_LLM_Breadcrumb::excerpt( self::detail( $response ), 200 ),
                ),
            ),
            self::CAP
        );

        return $reason;
    }

    /**
     * Numeric status, or 0 when there never was one.
     *
     * @param mixed $response Return value of wp_remote_post().
     *
     * @return int
     */
    private static function status_code( $response ) {
        if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
            return (int) $response['response']['code'];
        }

        return 0;
    }

    /**
     * The most useful sentence the failure carries.
     *
     * For a WP_Error that is the transport message, for an HTTP error it is the
     * provider's own error text, which is where the actionable part lives. An
     * OpenRouter 402 says how short the balance is, and a 400 names the field it
     * rejected.
     *
     * @param mixed $response Return value of wp_remote_post().
     *
     * @return string
     */
    private static function detail( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response->get_error_code() . ': ' . $response->get_error_message();
        }

        if ( ! is_array( $response ) || ! isset( $response['body'] ) || ! is_string( $response['body'] ) ) {
            return '';
        }

        $body = json_decode( $response['body'], true );

        if ( is_array( $body ) && isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
            return $body['error']['message'];
        }

        return $response['body'];
    }
}
