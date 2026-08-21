<?php
/**
 * Keep strings that cannot be translated out of the engine request.
 *
 * TranslatePress stores every string it meets on a rendered page, then offers
 * all of them to the machine translator. On a catalogue site with an open search
 * box that means bot queries become permanent dictionary rows and get sent to a
 * paid API. Measured in wp_trp_dictionary_en_us_de_de on 2026-08-17, out of
 * 564.933 rows, 16.686 are search result page titles built from a visitor query,
 * for example "Suchergebnisse: „fivem hentai“". Those are one time bot queries
 * that no visitor will ever search for again, and no engine can turn them into
 * anything but themselves.
 *
 * This is deliberately a thin second gate. The vendor already runs a thorough
 * one: TRP_Machine_Translator::should_translate_string() rejects empty strings,
 * bare URLs, email addresses and media file references before translate_array()
 * is ever called, for every engine. Adding a URL rule here was measured against
 * that and found redundant, so only two rules remain:
 *
 *   SEARCH_TITLE  the vendor has no notion of a search result page
 *   no letter     the vendor's punctuation class is ASCII, so a string like
 *                 "12,90 € – 24,90 €" still reaches the engine
 *
 * A skipped string is returned mapped to itself rather than dropped. That is the
 * vendor's own pattern for its skips (class-machine-translator.php, where
 * $strings_to_skip is merged back as $original => $original). Dropping the key
 * instead would leave the row at status 0, so the render layer would re-harvest
 * and re-offer that string on every future render of every page carrying it,
 * with no terminating condition. Mapping to itself costs one storage write, once.
 *
 * The rules are narrow on purpose. A wider "starts with Search" rule was checked
 * against the live dictionary first and would have swallowed real navigation
 * copy: "Search FiveMX products, scripts and MLOs" appears 283 times and must
 * keep being translated.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Translation_Skiplist {

    /**
     * Search result page titles.
     *
     * Only the two locales that occur as originals are matched. The default
     * language is English and the storefront localizer emits the German form, so
     * those are the two shapes the harvester ever stores. Live forms:
     *
     *   Search Results
     *   Search results: “fivem”
     *   Search results for fivem
     *   Suchergebnisse: „fivem“
     *   Suchergebnisse für fivem
     *
     * The separator is required. Without it the pattern would also match
     * "Search FiveMX products, scripts and MLOs", which is real navigation copy.
     * The stems run through a filter so a locale can be added from an mu-plugin
     * rather than by editing this class.
     */
    const SEARCH_TITLE_STEMS = array( 'search results', 'suchergebnisse' );

    /**
     * Decide whether a string is worth an API call.
     *
     * @param mixed $string Candidate source string.
     *
     * @return bool True when the string must not be sent to an engine.
     */
    public static function should_skip( $string ) {
        if ( ! is_string( $string ) ) {
            return true;
        }

        $trimmed = trim( $string );

        if ( $trimmed === '' ) {
            return true;
        }

        // Nothing to translate: digits, currency symbols, punctuation, entities.
        if ( ! preg_match( '/\p{L}/u', $trimmed ) ) {
            return true;
        }

        return (bool) preg_match( self::search_title_pattern(), $trimmed );
    }

    /**
     * Build the search title pattern from the filtered stem list.
     *
     * @return string
     */
    private static function search_title_pattern() {
        $stems = apply_filters( 'trp_llm_skiplist_search_stems', self::SEARCH_TITLE_STEMS );

        if ( ! is_array( $stems ) || $stems === array() ) {
            $stems = self::SEARCH_TITLE_STEMS;
        }

        $quoted = array();

        foreach ( $stems as $stem ) {
            if ( is_string( $stem ) && trim( $stem ) !== '' ) {
                $quoted[] = preg_quote( trim( $stem ), '/' );
            }
        }

        return '/^(' . implode( '|', $quoted ) . ')(\s*:|\s+(for|für)\b|\s*$)/iu';
    }

    /**
     * Split a set into what an engine should see and what it should not.
     *
     * @param array $strings Strings keyed as TranslatePress keys them.
     *
     * @return array{send:array,skip:array} Both keyed like the input. Every entry
     *                                      in "skip" maps to its own original, so
     *                                      the caller can return it as a finished
     *                                      translation.
     */
    public static function partition( $strings ) {
        $send = array();
        $skip = array();

        if ( ! is_array( $strings ) ) {
            return array( 'send' => $send, 'skip' => $skip );
        }

        foreach ( $strings as $key => $string ) {
            if ( self::should_skip( $string ) ) {
                if ( is_string( $string ) ) {
                    $skip[ $key ] = $string;
                }

                continue;
            }

            $send[ $key ] = $string;
        }

        return array( 'send' => $send, 'skip' => $skip );
    }
}
