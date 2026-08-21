<?php
/**
 * Remember for a few minutes whether an API key works.
 *
 * check_api_key_validity() runs while the machine translation settings screen is
 * being rendered, and the screen renders one panel per engine, so opening it used
 * to fire a real translation request. That is a paid call to answer a question
 * that has nothing to do with translating anything, repeated on every page load
 * and every failed save.
 *
 * Two changes fix that together: the engines now probe a free endpoint instead of
 * translating, and the verdict is cached here. Five minutes is short enough that
 * pasting a corrected key and saving shows the new verdict rather than the old
 * one, and long enough that a settings screen someone is actually working on does
 * not re-probe on every render.
 *
 * Keyed by the API key, so changing the key invalidates the verdict without
 * anything having to remember to clear it.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Key_Verdict {

    /**
     * How long a verdict is trusted.
     */
    const TTL = 300;

    /**
     * The cached verdict, or the one this probe produces.
     *
     * The read, probe and write cycle was written out in all four engines. The
     * shape of the verdict array is validated in read(), so four copies of that
     * shape meant one drifting copy could make the cache silently stop working
     * with no symptom at all.
     *
     * @param string   $engine  Engine slug.
     * @param string   $api_key API key the verdict is about.
     * @param callable $probe   Returns a verdict with 'error' and 'message'.
     *
     * @return array
     */
    public static function remember( $engine, $api_key, $probe ) {
        $verdict = self::read( $engine, $api_key );

        if ( null !== $verdict ) {
            return $verdict;
        }

        $verdict = call_user_func( $probe );

        self::write( $engine, $api_key, $verdict );

        return $verdict;
    }

    /**
     * @param string $engine  Engine slug.
     * @param string $api_key API key the verdict is about.
     *
     * @return array|null Null when nothing is cached.
     */
    public static function read( $engine, $api_key ) {
        $verdict = get_transient( self::key( $engine, $api_key ) );

        if ( ! is_array( $verdict ) || ! array_key_exists( 'error', $verdict ) || ! array_key_exists( 'message', $verdict ) ) {
            return null;
        }

        return $verdict;
    }

    /**
     * @param string $engine  Engine slug.
     * @param string $api_key API key the verdict is about.
     * @param array  $verdict Verdict with 'error' and 'message'.
     *
     * @return void
     */
    public static function write( $engine, $api_key, $verdict ) {
        set_transient( self::key( $engine, $api_key ), $verdict, self::TTL );
    }

    /**
     * @param string $engine  Engine slug.
     * @param string $api_key API key.
     *
     * @return string
     */
    public static function key( $engine, $api_key ) {
        return 'trp_llm_key_valid_' . $engine . '_' . md5( (string) $api_key );
    }
}
