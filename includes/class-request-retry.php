<?php
/**
 * One bounded retry, and the reasons not to make it two.
 *
 * A failed chunk used to be dropped and left at status 0, which means the render
 * layer offers the same strings again on the next render of any page carrying
 * them. A single 503 therefore cost a page's worth of translation and then
 * repeated on every later render. A retry fixes the common case where the second
 * call succeeds.
 *
 * The ceiling on that retry is not a style preference. Translation runs inline
 * during page render under a request wide budget that TranslatePress sets in
 * $GLOBALS['trp_machine_translation_deadline'], and the origin runs prefork with
 * a fixed worker count. Every second spent sleeping here is a worker held open
 * against a live visitor. So: one retry, a backoff measured in single seconds,
 * and the whole retry skipped when it would run past the deadline. Anything
 * larger belongs to the guarded backfill scripts, which run off the render path.
 *
 * A permanent failure is never retried. A 401 does not become a 200 by asking
 * twice, and asking twice doubles the damage of a rate limit.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Request_Retry {

    /**
     * Status codes that a second identical request can plausibly survive.
     *
     * 429 is deliberately absent. Asking a rate limit twice makes the rate limit
     * worse and spends a second doomed call plus a sleep on the render path, for
     * an answer the provider has already given. TRP_LLM_Engine_Cooldown handles
     * it instead, off the render path and honouring Retry-After in full.
     */
    const RETRYABLE_CODES = array( 500, 502, 503, 504 );

    /**
     * WP_Error codes that mean the request never reached the provider.
     */
    const RETRYABLE_ERRORS = array( 'http_request_failed', 'connect_error', 'timeout' );

    /**
     * Seconds the answer still needs after the socket closes.
     *
     * Parsing, the placeholder guard and the dictionary write are not free, and a
     * timeout that consumes the last second of the budget leaves nothing to store
     * what it just paid for.
     */
    const BUDGET_HEADROOM = 1;

    /**
     * Below this many seconds a request is not worth issuing.
     *
     * Deliberately not the old floor of five reinterpreted. The old five was a
     * minimum timeout, so a spent budget still sent something. This is a minimum
     * amount of budget, so a spent budget sends nothing.
     *
     * TranslatePress grants ten seconds per render in class-translation-render.php
     * and class-gettext-manager.php, both through the trp_machine_translation_time_budget
     * filter. Five out of those ten is a compromise: raising it refuses more doomed
     * requests and translates less per render, and the real fix for a site that
     * needs both is to raise the budget itself or to translate off the render path.
     */
    const MIN_USEFUL_SECONDS = 5;

    /**
     * Decide whether a response is worth sending again.
     *
     * @param mixed $response Return value of wp_remote_post().
     *
     * @return string One of 'ok', 'retryable', 'permanent'.
     */
    public static function classify( $response ) {
        if ( is_wp_error( $response ) ) {
            $code = $response->get_error_code();

            foreach ( self::RETRYABLE_ERRORS as $retryable ) {
                if ( false !== strpos( (string) $code, $retryable ) ) {
                    return 'retryable';
                }
            }

            return 'permanent';
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 === $code ) {
            return 'ok';
        }

        if ( 0 === $code ) {
            return 'permanent';
        }

        return in_array( $code, self::RETRYABLE_CODES, true ) ? 'retryable' : 'permanent';
    }

    /**
     * How long the provider asked us to wait, clamped to what a render can spend.
     *
     * Retry-After comes in two forms and providers use both: a number of seconds
     * and an HTTP date. A missing or unparsable header means one second, which is
     * long enough to clear a burst limit and short enough to be invisible.
     *
     * @param mixed $response Return value of wp_remote_post().
     * @param int   $max      Upper bound in seconds.
     *
     * @return int
     */
    public static function retry_after_seconds( $response, $max ) {
        $max   = max( 0, (int) $max );
        $value = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );

        if ( '' === $value ) {
            return min( 1, $max );
        }

        if ( ctype_digit( $value ) ) {
            return min( (int) $value, $max );
        }

        $when = strtotime( $value );

        if ( false === $when ) {
            return min( 1, $max );
        }

        return min( max( 0, $when - time() ), $max );
    }

    /**
     * Send, and send once more if the first answer was worth a second try.
     *
     * @param callable $sender Returns a wp_remote_post() result.
     *
     * @return mixed The last response received.
     */
    public static function send( $sender ) {
        $attempts    = max( 1, (int) apply_filters( 'trp_llm_max_attempts', 2 ) );
        $max_backoff = max( 0, (int) apply_filters( 'trp_llm_max_backoff', 2 ) );

        for ( $attempt = 1; ; $attempt++ ) {
            $response = call_user_func( $sender );

            if ( $attempt >= $attempts || 'retryable' !== self::classify( $response ) ) {
                return $response;
            }

            $delay = self::retry_after_seconds( $response, $max_backoff );

            // The retried request itself has to fit, not only the pause before
            // it. A retry that clears the sleep check and then blocks for its own
            // timeout holds a prefork worker long past a budget declared in
            // seconds, which is the collapse mode this guard exists to prevent.
            if ( ! self::send_is_worthwhile( $delay ) ) {
                return $response;
            }

            if ( $delay > 0 ) {
                sleep( $delay );
            }
        }
    }

    /**
     * Seconds left of the render budget, or INF when there is no budget.
     *
     * No deadline means no render is waiting, which is the case for the backfill
     * and probe scripts in skripte/.
     *
     * @return float
     */
    public static function remaining_budget() {
        if ( ! isset( $GLOBALS['trp_machine_translation_deadline'] ) ) {
            return INF;
        }

        return (float) $GLOBALS['trp_machine_translation_deadline'] - microtime( true );
    }

    /**
     * How long one request may block, never longer than the budget allows.
     *
     * The old floor here was max( 5, ... ), and it was the whole content of
     * trp_llm_http_failures. Measured on production on 2026-08-27, every entry in
     * that log was a cURL 28, and the 5000, 5001 and 5002 millisecond ones were
     * the floor doing its work: the deadline had already passed, remaining_budget()
     * was negative, and the floor rounded that back up into a request that was
     * issued anyway with five seconds to live. The provider generates and bills the
     * completion whether or not we are still listening, so those were paid for and
     * discarded.
     *
     * The floor is gone. What replaces it is send_is_worthwhile(), because refusing
     * to send is a decision for the caller, not something a timeout can express.
     * The value returned here stays at or above one second so that it is always
     * safe to hand to a transport: CURLOPT_TIMEOUT of zero means no timeout at all,
     * and turning a spent budget into an unbounded request would be worse than the
     * bug being fixed.
     *
     * @return int
     */
    public static function request_timeout() {
        $ceiling = max( 1, (int) apply_filters( 'trp_llm_request_timeout', 60 ) );
        $left    = self::remaining_budget();

        if ( INF === $left ) {
            return $ceiling;
        }

        return (int) min( $ceiling, max( 1, floor( $left - self::BUDGET_HEADROOM ) ) );
    }

    /**
     * Whether a request is worth issuing at all right now.
     *
     * Answers the question request_timeout() cannot: a two second timeout is a
     * valid number and a doomed request. The 7000, 8001 and 9000 millisecond
     * timeouts in the same production log were requests that had most of the budget
     * and still did not finish, so a request with a couple of seconds left is not a
     * gamble worth the money.
     *
     * INF short circuits before anything else. No deadline means no render is
     * waiting, which is the case for WP-CLI, cron and the backfill scripts, and
     * those must keep the full ceiling and must never be refused.
     *
     * @param float $extra_seconds Seconds that will be spent before the request,
     *                             such as a retry backoff.
     *
     * @return bool
     */
    public static function send_is_worthwhile( $extra_seconds = 0 ) {
        $left = self::remaining_budget();

        if ( INF === $left ) {
            return true;
        }

        $minimum = max( 1, (int) apply_filters( 'trp_llm_min_request_seconds', self::MIN_USEFUL_SECONDS ) );

        if ( $left - self::BUDGET_HEADROOM < $minimum ) {
            return false;
        }

        return self::fits_in_budget( $extra_seconds + self::request_timeout() );
    }

    /**
     * Whether spending this many seconds still leaves room in the render budget.
     *
     * @param float $seconds Seconds we are about to spend.
     *
     * @return bool
     */
    public static function fits_in_budget( $seconds ) {
        return $seconds < self::remaining_budget();
    }
}
