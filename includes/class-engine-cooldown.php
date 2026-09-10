<?php
/**
 * Stop asking a provider that has already said no.
 *
 * This is the terminating condition the engines never had, and it matters more
 * than the retry beside it.
 *
 * TranslatePress stores every string it meets on a rendered page and offers the
 * untranslated ones to the engine on every later render. On a catalogue site
 * that is tens of thousands of pending rows across the locales. Nothing in the
 * old code noticed that the previous call had failed, so an expired key, a spent
 * balance or a rate limit produced one doomed API call per chunk per render, for
 * as long as the site received traffic. The 50 log entries inside a three minute
 * window measured on 2026-08-17 are what that looks like from the outside.
 *
 * A cooldown converts that into a bounded silence. The engine stops calling,
 * every string stays at status 0 and is retried later, and the failure is
 * recorded once rather than thousands of times.
 *
 * The lengths are chosen against what the failure means, not as one number. A
 * key or balance problem needs a human, so 15 minutes costs nothing and saves a
 * lot. A rate limit is the provider telling us when to come back, so it is
 * honoured, with a floor because a burst limit that answers immediately is not
 * really over.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Engine_Cooldown {

    /**
     * Transient prefix. One cooldown per engine, not per model or per language:
     * an authentication or balance failure is a property of the account.
     */
    const PREFIX = 'trp_llm_cooldown_';

    /**
     * End a cooldown early.
     *
     * There is exactly one caller and it is the settings save, not the render
     * path. A cooldown outlives the problem that started it: someone who has
     * just pasted a working key has already fixed what the cooldown is waiting
     * out, and should not sit through the remaining quarter of an hour to find
     * that out. On the render path this method would be unreachable, because
     * translate_array() only gets that far once active() has returned empty.
     *
     * @param string $engine Engine slug.
     *
     * @return void
     */
    public static function clear( $engine ) {
        unset( self::$noted[ $engine ] );

        delete_transient( self::PREFIX . $engine );
    }

    /**
     * How long to stay quiet after a failure that needs a human.
     */
    const CREDENTIAL_SECONDS = 900;

    /**
     * Shortest useful pause after a rate limit.
     */
    const RATE_LIMIT_FLOOR = 60;

    /**
     * Legacy constant retained for callers; no longer caps server-directed waits.
     */
    const RATE_LIMIT_CEILING = 3600;

    /**
     * How long to stay quiet after any other permanent failure.
     *
     * Shorter than the credential pause because the cause is less certain, and
     * far from zero because the defining property of a permanent failure is that
     * an identical request will fail identically.
     */
    const PERMANENT_SECONDS = 300;

    /**
     * How long to stay quiet after a gateway error or a timeout.
     *
     * Short, because unlike a dead key this really can clear on its own. But not
     * zero, which is what it was. A provider answering 503 to the first chunk of
     * a page answers 503 to the other nineteen, and with no cooldown the loop
     * sent all twenty inside a ten second render budget, then wrote twenty ring
     * buffer rows about it, then did the whole thing again on the next render.
     * One minute is long enough to cover a page render and short enough that a
     * brief blip costs one page rather than an afternoon.
     */
    const RETRYABLE_SECONDS = 60;

    /**
     * Engines whose skip has already been recorded during this request.
     *
     * @var array<string, bool>
     */
    private static $noted = array();

    /**
     * The reason this engine is quiet, or an empty string when it is not.
     *
     * @param string $engine Engine slug.
     *
     * @return string
     */
    public static function active( $engine ) {
        $value = get_transient( self::PREFIX . $engine );

        return is_string( $value ) ? $value : '';
    }

    /**
     * Begin a cooldown sized to what went wrong, or do nothing when nothing did.
     *
     * @param string $engine   Engine slug.
     * @param string $reason   Reason from TRP_LLM_Http_Failure_Log::reason_for().
     * @param mixed  $response Return value of wp_remote_post().
     *
     * @return int Seconds the cooldown will last, 0 when none was started.
     */
    public static function start( $engine, $reason, $response = null, $classification = '' ) {
        $seconds = self::seconds_for( $reason, $response, $classification, $engine );

        if ( $seconds <= 0 ) {
            return 0;
        }

        set_transient( self::PREFIX . $engine, $reason, $seconds );

        return $seconds;
    }

    /**
     * Whether this skip is the first one this request, so it is worth recording.
     *
     * TranslatePress calls the engine once per chunk of get_chunk_size(), so a
     * page carrying hundreds of untranslated strings enters a cooled down engine
     * dozens of times. Recording each of those would put dozens of identical rows
     * into a 50 slot ring buffer, flushing every other cause out of it within a
     * couple of renders, and would do a wp_options read modify write for each one
     * during exactly the incident the cooldown exists to make cheap.
     *
     * @param string $engine Engine slug.
     *
     * @return bool
     */
    public static function note_skip( $engine ) {
        if ( isset( self::$noted[ $engine ] ) ) {
            return false;
        }

        self::$noted[ $engine ] = true;

        return true;
    }

    /**
     * How long a given failure should silence an engine.
     *
     * A 5xx is deliberately not on this list. A gateway blip is not a reason to
     * stop translating for a quarter of an hour, and the retry beside this
     * already absorbs the common case.
     *
     * @param string $reason   Reason from TRP_LLM_Http_Failure_Log::reason_for().
     * @param mixed  $response Return value of wp_remote_post().
     *
     * @return int
     */
    public static function seconds_for( $reason, $response = null, $classification = '', $engine = '' ) {
        $header = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
        $requested = '' === $header ? 0 : TRP_LLM_Request_Retry::retry_after_seconds( $response, PHP_INT_MAX - time() );
        if ( 'http-401' === $reason || 'http-402' === $reason ) {
            $seconds = apply_filters( 'trp_llm_credential_cooldown', self::CREDENTIAL_SECONDS, $reason, $engine );
        } elseif ( 'http-429' === $reason ) {
            $seconds = apply_filters( 'trp_llm_rate_limit_cooldown', max( self::RATE_LIMIT_FLOOR, $requested ), $requested, $engine );
        } elseif ( 'permanent' === $classification ) {
            $seconds = apply_filters( 'trp_llm_permanent_cooldown', self::PERMANENT_SECONDS, $reason, $engine );
        } elseif ( 'retryable' === $classification ) {
            $seconds = apply_filters( 'trp_llm_retryable_cooldown', self::RETRYABLE_SECONDS, $reason, $engine );
        } else {
            return 0;
        }
        // A filter may lengthen a server-directed wait but must not shorten it.
        return max( $requested, (int) $seconds );
    }
}
