<?php
/**
 * Turn what an LLM actually returns into what TranslatePress expects.
 *
 * The engines ask for "a JSON array with the translated strings in the same
 * order as the input" and then trust the answer to be exactly that. On
 * 2026-08-14 between 21:19 and 21:22 UTC google/gemini-2.5-flash-lite answered
 * with the right number of elements in the wrong shape, most likely an array of
 * objects rather than an array of strings. Every element reached the placeholder
 * guard as an array, the guard rejected all of them because a non-string can
 * never match a placeholder inventory, and 200 strings were dropped. The only
 * trace was 200 log rows whose translation read "Array", which is what PHP casts
 * an array to.
 *
 * Two failure modes hid behind that.
 *
 *   1. Shape. A wrapper object ({"translations": [...]}) or per-element objects
 *      ([{"translation": "..."}]) are both common LLM answers and both used to
 *      be unusable. They are recoverable without another API call.
 *
 *   2. Silence. When the element count does not match the chunk, the engines
 *      discard the chunk inside an if statement with no else branch. Nothing is
 *      recorded, so a systematic model change looks exactly like an idle site.
 *
 * This class fixes the first and makes the second visible. It never invents a
 * translation: an element it cannot reduce to a string becomes null, which
 * TRP_LLM_Placeholder_Guard::filter_chunk() already treats as "leave this string
 * untranslated and try again later".
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Response_Normalizer {

    /**
     * Keys a model uses when it wraps the list in an object instead of
     * returning the list itself.
     */
    const WRAPPER_KEYS = array(
        'translations',
        'translated',
        'items',
    );

    /**
     * Keys a model uses when it wraps each translation in its own object.
     *
     * Both lists are deliberately short. Generic names such as "text", "value"
     * or "data" were considered and left out: a model that echoes the source
     * beside the translation names the source "text" just as often, so a generic
     * key is as likely to harvest the English as the German. An unrecognised
     * shape becomes null and is retried, which is the safe direction.
     */
    const ELEMENT_KEYS = array(
        'translation',
        'translated',
        'translated_text',
    );

    /**
     * Parse an engine's raw message content into a positional list.
     *
     * @param string $content Raw content of the assistant message.
     *
     * @return array List of strings, with null where an element could not be
     *               reduced to one. Empty array when nothing parsed at all.
     */
    public static function parse( $content ) {
        if ( ! is_string( $content ) ) {
            return array();
        }

        $content = self::strip_paragraph_wrapper( self::strip_code_fence( $content ) );
        $decoded = json_decode( $content, true );

        if ( json_last_error() === JSON_ERROR_NONE ) {
            if ( is_string( $decoded ) ) {
                $nested = json_decode( $decoded, true );

                if ( json_last_error() === JSON_ERROR_NONE && is_array( $nested ) ) {
                    $decoded = $nested;
                } else {
                    return array( $decoded );
                }
            } elseif ( ! is_array( $decoded ) ) {
                return array();
            }
        } else {
            // Gemini occasionally returns only the comma-separated JSON values.
            // Adding the two missing delimiters is deterministic; anything that
            // is not valid JSON after that remains unusable.
            $decoded = json_decode( '[' . $content . ']', true );

            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                return array();
            }
        }

        $list = self::unwrap( $decoded );

        if ( $list === array() ) {
            return array();
        }

        $normalized = array();

        foreach ( $list as $element ) {
            $normalized[] = self::to_string( $element );
        }

        return $normalized;
    }

    /**
     * Remove a fenced code block that wraps the whole answer.
     *
     * Anchored on purpose. The previous implementation stripped every backtick
     * run anywhere in the content, which corrupted any translation that
     * legitimately contained one, and a FiveM catalogue is full of config
     * snippets.
     *
     * @param string $content Raw content.
     *
     * @return string
     */
    private static function strip_code_fence( $content ) {
        $content = trim( $content );

        // One anchored pattern covers both a closed fence and one that was cut
        // off before it closed, because the trailing fence is removed after the
        // match rather than being part of it.
        if ( preg_match( '/^```[a-zA-Z0-9_+-]*[ \t]*\R(.*)$/s', $content, $matches ) ) {
            return trim( preg_replace( '/\R```$/', '', $matches[1] ) );
        }

        return $content;
    }

    /**
     * Remove the exact HTML paragraph wrapper OpenRouter sometimes adds.
     *
     * Only a single paragraph around the whole response is accepted. General
     * HTML stripping would turn an explanatory refusal into apparently valid
     * content and could store it as a translation.
     *
     * @param string $content Raw content after code-fence normalization.
     *
     * @return string
     */
    private static function strip_paragraph_wrapper( $content ) {
        if ( preg_match( '/^<p>\s*(.*?)\s*<\/p>$/si', trim( $content ), $matches ) ) {
            return trim( $matches[1] );
        }

        return trim( $content );
    }

    /**
     * Reduce a decoded body to the positional list of translations.
     *
     * @param array $decoded Decoded JSON.
     *
     * @return array
     */
    private static function unwrap( array $decoded ) {
        if ( array_is_list( $decoded ) ) {
            return $decoded;
        }

        foreach ( self::WRAPPER_KEYS as $key ) {
            if ( isset( $decoded[ $key ] ) && is_array( $decoded[ $key ] ) && array_is_list( $decoded[ $key ] ) ) {
                return $decoded[ $key ];
            }
        }

        // An object keyed by position, for example {"1": "...", "2": "..."}.
        // json_decode already collapses a zero based run into a list, so what
        // arrives here is a run that starts elsewhere or skips a number.
        foreach ( array_keys( $decoded ) as $key ) {
            if ( ! is_int( $key ) && ! ( is_string( $key ) && ctype_digit( $key ) ) ) {
                return array();
            }
        }

        ksort( $decoded, SORT_NUMERIC );

        return array_values( $decoded );
    }

    /**
     * Reduce one element to a translation string.
     *
     * @param mixed $element One decoded element.
     *
     * @return string|null Null when the element carries no usable string.
     */
    private static function to_string( $element ) {
        if ( is_string( $element ) ) {
            return $element;
        }

        if ( is_int( $element ) || is_float( $element ) ) {
            return (string) $element;
        }

        if ( ! is_array( $element ) ) {
            return null;
        }

        foreach ( self::ELEMENT_KEYS as $key ) {
            if ( isset( $element[ $key ] ) && is_string( $element[ $key ] ) ) {
                return $element[ $key ];
            }
        }

        // A single valued list such as ["..."] carries no ambiguity. An object
        // does, and guessing there is how {"error": "rate limited"} would end up
        // stored as a translation, so a named key it does not recognise is left
        // alone.
        if ( count( $element ) === 1 && array_is_list( $element ) ) {
            $only = reset( $element );

            if ( is_string( $only ) ) {
                return $only;
            }
        }

        return null;
    }

    /**
     * Record a chunk the engine had to throw away whole.
     *
     * Kept to the last 50 entries in a non autoloaded option, the same
     * breadcrumb shape TRP_LLM_Placeholder_Guard uses for rejected strings.
     * Without this a model that changes its answer format silently translates
     * nothing at all.
     *
     * @param string $engine   Engine slug.
     * @param string $reason   Short machine readable reason.
     * @param int    $expected Strings sent in the chunk.
     * @param int    $received Elements parsed out of the answer.
     * @param string $sample   Raw content, truncated.
     *
     * @return void
     */
    public static function log_chunk_failure( $engine, $reason, $expected, $received, $sample = '' ) {
        TRP_LLM_Breadcrumb::append(
            'trp_llm_chunk_failures',
            array(
                array(
                    'engine'   => $engine,
                    'time'     => time(),
                    'reason'   => $reason,
                    'expected' => (int) $expected,
                    'received' => (int) $received,
                    'sample'   => TRP_LLM_Breadcrumb::excerpt( $sample ),
                ),
            ),
            50
        );
    }
}
