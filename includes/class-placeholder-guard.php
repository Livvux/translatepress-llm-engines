<?php
/**
 * Placeholder integrity guard for LLM translation engines.
 *
 * An LLM occasionally returns a translation in which a proper noun has been
 * swapped for a printf placeholder, for example
 *
 *   "Recycle Job - FiveMX - Free FiveM Job | FiveMX"
 *     becomes "Recycle Job - %s - Kostenloser %s Job | %s"
 *
 * The rate is low (about 0.06% of strings in a large batch), so it survives
 * spot checks and lands straight in title and meta description tags. The same
 * lapse also drops placeholders the source really had.
 *
 * This guard compares the placeholder inventory of source and translation and
 * rejects any translation whose inventory does not match. A rejected string is
 * left out of the result set, so TranslatePress keeps it untranslated and
 * retries it on a later run instead of storing corrupted text.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Placeholder_Guard {

    /**
     * Placeholder syntaxes that must survive translation unchanged.
     *
     * printf is deliberately narrow: %s, %d and their positional forms
     * (%1$s, %2$d). That is what WordPress strings actually carry, and what an
     * engine actually invents. A wider pattern was measured against all
     * 2.64M live dictionary rows and produced far more false positives than
     * real findings:
     *
     *   "100% ready"  ->  "100%-bereit"      matched "%-b" as a placeholder
     *   "50% off"                            matched "% o" as a placeholder
     *   "data:image/svg+xml,%3Csvg%20xmlns"  matched "%20x" as a placeholder
     *
     * The lookbehind rules out a digit directly before the percent sign, so
     * prose like "100%safe" stays prose while " %s" stays a placeholder.
     * The hex lookahead rules out percent-encoding, so a URL-encoded slug like
     * "%d9%87%d8%af" is not read as a run of %d placeholders. A real %d is
     * followed by a space or punctuation, never by a second hex digit.
     *
     * mustache covers {{name}}, brace covers {name}. Both were kept after the
     * same corpus run found real damage they alone catch, for example a Twig
     * block stored as "{% fuer Link in Spalte.Links %}" and a variable renamed
     * to "{{ Geschwindigkeit }}".
     *
     * TranslatePress swaps protected terms and positional placeholders for
     * opaque 1TP<n>T tokens before an engine sees the string. Those tokens must
     * be checked here as well as printf placeholders: rejecting them in the HTTP
     * response layer discarded every safe sibling in the same model response.
     */
    const PATTERNS = array(
        'printf'   => '/(?<!\d)%(?![0-9A-Fa-f]{2})(?:\d+\$)?[sd]/',
        'trp'      => '/1TP\d+T/',
        'mustache' => '/\{\{[^}]*\}\}/',
        'brace'    => '/(?<!\{)\{[a-zA-Z_][a-zA-Z0-9_]*\}(?!\})/',
    );

    /**
     * Decide whether a translation may be stored.
     *
     * @param string $source      Original string as sent to the engine.
     * @param string $translation Translated string as returned by the engine.
     *
     * @return bool True when the placeholder inventory matches.
     */
    public static function is_safe( $source, $translation ) {
        if ( ! is_string( $source ) || ! is_string( $translation ) ) {
            return false;
        }

        // An empty answer has the same placeholder inventory as ordinary prose,
        // but storing it would erase visible content. Keep legitimately blank
        // sources valid, including Unicode whitespace used for layout.
        if ( 1 === preg_match( '/^\s*$/uD', $translation ) && 1 !== preg_match( '/^\s*$/uD', $source ) ) {
            return false;
        }

        foreach ( self::PATTERNS as $pattern ) {
            if ( self::inventory( $pattern, $source ) !== self::inventory( $pattern, $translation ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sorted multiset of the placeholders in a string.
     *
     * Sorting means a reordered translation such as "%2$s vor %1$s" still
     * passes, while a dropped, added or altered placeholder does not.
     *
     * @param string $pattern Regex from self::PATTERNS.
     * @param string $subject String to scan.
     *
     * @return array
     */
    private static function inventory( $pattern, $subject ) {
        if ( ! preg_match_all( $pattern, $subject, $matches ) ) {
            return array();
        }

        $found = $matches[0];
        sort( $found );

        return $found;
    }

    /**
     * Filter a chunk result down to the translations that are safe to store.
     *
     * @param array  $source_chunk Original strings, keyed as TranslatePress keys them.
     * @param array  $translations Engine output, indexed 0..n in chunk order.
     * @param string $engine       Engine slug, for the log line.
     *
     * @return array Translations keyed like $source_chunk, rejected keys omitted.
     */
    public static function filter_chunk( $source_chunk, $translations, $engine = '' ) {
        $safe     = array();
        $rejected = array();
        $i        = 0;

        foreach ( $source_chunk as $key => $source ) {
            $translation = isset( $translations[ $i ] ) ? $translations[ $i ] : null;
            $i++;

            if ( $translation === null ) {
                continue;
            }

            if ( self::is_safe( $source, $translation ) ) {
                $safe[ $key ] = $translation;
                continue;
            }

            $rejected[] = array(
                'source'      => $source,
                'translation' => $translation,
            );
        }

        if ( ! empty( $rejected ) ) {
            self::log_rejected( $rejected, $engine );
        }

        return $safe;
    }

    /**
     * Record rejected translations so a rising rate stays visible.
     *
     * Kept to the last 200 entries. This is a diagnostic breadcrumb, not an
     * archive, so the option is stored without autoload.
     *
     * @param array  $rejected Rejected source and translation pairs.
     * @param string $engine   Engine slug.
     *
     * @return void
     */
    private static function log_rejected( $rejected, $engine ) {
        $entries = array();

        foreach ( $rejected as $entry ) {
            $entries[] = array(
                'engine'      => $engine,
                'time'        => time(),
                'source'      => TRP_LLM_Breadcrumb::excerpt( $entry['source'] ),
                'translation' => TRP_LLM_Breadcrumb::excerpt( $entry['translation'] ),
            );
        }

        TRP_LLM_Breadcrumb::append( 'trp_llm_placeholder_rejections', $entries, 200 );
    }
}
