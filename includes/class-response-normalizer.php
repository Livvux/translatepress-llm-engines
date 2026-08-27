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

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $decoded = self::repair( $content );

            if ( null === $decoded ) {
                return array();
            }
        }

        // Runs for a strict decode and for a repaired one alike. Keeping it in one
        // place is what makes the repairs useful: the stray bracket case recovers
        // to a JSON string, and a repair path that only accepted arrays would throw
        // away the very answer it had just rescued.
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
     * Reduce an answer that repeated itself back to one copy.
     *
     * Production returned expected 1 received 2 and expected 1 received 4, where the
     * extra elements were the same sentence again and the final copy was often cut
     * mid word. The whole chunk was billed and discarded.
     *
     * Truncating to the expected count is only safe when the surplus is provably an
     * echo, and the unsafe reading is realistic: a model that split one source
     * string on a pipe or a sentence boundary also produces more elements than were
     * asked for. There, element zero is a fragment of source zero, and truncating
     * would store a partial translation under a full source key, positionally
     * misaligned, possibly carrying every placeholder the guard checks for and
     * therefore passing it. Silently storing a wrong translation is the worst
     * outcome available here, worse than dropping a chunk that will be offered
     * again, so anything short of proof returns null.
     *
     * Proof is two conditions together. The count has to be a whole multiple of the
     * expectation, because a partial repeat is indistinguishable from a split. And
     * every surplus element has to match the element it would be echoing.
     *
     * @param array $list     Parsed translations.
     * @param int   $expected How many the chunk asked for.
     *
     * @return array|null Trimmed list, or null when this is not an echo.
     */
    public static function trim_repetition( array $list, $expected ) {
        $received = count( $list );

        if ( $expected < 1 || $received <= $expected || 0 !== $received % $expected ) {
            return null;
        }

        // Every element past the first copy has to match the element of the first
        // copy it is echoing. Each is compared against the first copy rather than
        // against the copy before it, because near_duplicate() is not transitive.
        for ( $i = $expected; $i < $received; $i++ ) {
            if ( ! self::near_duplicate( $list[ $i % $expected ], $list[ $i ] ) ) {
                return null;
            }
        }

        return array_slice( $list, 0, $expected );
    }

    /**
     * Whether one string is the same answer as another, or a cut off copy of it.
     *
     * The prefix rule is what the production samples need. A repeated answer whose
     * second copy ran out of output tokens is not equal to the first, it is a
     * proper prefix of it, and that is the dominant shape in the log.
     *
     * Deliberately neither levenshtein(), which counts bytes and so misreads every
     * accented language this site publishes, nor similar_text(), whose worst case
     * is cubic and which would run on the render path.
     *
     * @param mixed $a First element.
     * @param mixed $b Second element.
     *
     * @return bool
     */
    private static function near_duplicate( $a, $b ) {
        if ( ! is_string( $a ) || ! is_string( $b ) ) {
            return false;
        }

        $a = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $a ) ) );
        $b = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $b ) ) );

        if ( '' === $a || '' === $b ) {
            return false;
        }

        if ( mb_strlen( $a ) > mb_strlen( $b ) ) {
            list( $a, $b ) = array( $b, $a );
        }

        // Two conditions doing two different jobs. The prefix test says what is
        // present matches, the length ratio says not too much is missing. Prefix
        // alone would accept two different catalogue titles that happen to share
        // an opening word.
        return mb_strlen( $a ) >= 0.6 * mb_strlen( $b ) && 0 === mb_strpos( $b, $a );
    }

    /**
     * Try a short ladder of deterministic repairs on content that would not parse.
     *
     * Only reached once strict decoding has already failed, which is the whole
     * safety argument: an answer that parsed is never rewritten, so a translation
     * that legitimately contains bracket or comma shaped text cannot be touched.
     *
     * The two repairs below were written from what production actually returned on
     * 2026-08-27, not from what a model might do in principle. Both failures cost a
     * whole chunk, billed, every time they happened.
     *
     * @param string $content Content that failed a strict decode.
     *
     * @return array|string|null Decoded value, or null when nothing recovered it.
     *                           A string is a legitimate outcome, so the caller
     *                           applies the same unwrapping it applies to a strict
     *                           decode rather than this deciding on its own.
     */
    private static function repair( $content ) {
        // Both repairs composed, rather than each on its own and then together.
        // Applying one cannot cost the other its fix: dropping a trailing comma
        // never changes bracket depth, and trimming an unmatched closer never
        // creates a trailing comma. So the composition succeeds wherever either
        // one alone would have, and the two separate attempts were four character
        // walks where two do the same job.
        $candidates = array(
            self::drop_trailing_commas( self::trim_unbalanced_brackets( $content ) ),
            // Gemini occasionally returns only the comma-separated JSON values.
            // Adding the two missing delimiters is deterministic, and anything that
            // is not valid JSON after that remains unusable. Last in the ladder,
            // because it is the broadest and would mask a more precise repair.
            '[' . $content . ']',
        );

        foreach ( $candidates as $candidate ) {
            if ( $candidate === $content || '' === $candidate ) {
                continue;
            }

            $decoded = json_decode( $candidate, true );

            if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_string( $decoded ) ) ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Delete a comma that sits directly before a closing bracket or brace.
     *
     * Production returned a fenced array whose last element carried a trailing
     * comma. json_decode rejects it, and the chunk was logged as empty-array and
     * thrown away.
     *
     * Deliberately a character scan and deliberately not preg_replace. The obvious
     * pattern, a comma followed by whitespace and a bracket, also matches inside a
     * string literal, and a catalogue of config snippets and option lists contains
     * exactly that. A translation reading "Optionen, ] und mehr" would be silently
     * rewritten. A corrupted translation that gets stored is a worse outcome than a
     * chunk that is dropped and retried, so the scan tracks whether it is inside a
     * string and only acts when it is not.
     *
     * @param string $content Raw content.
     *
     * @return string
     */
    private static function drop_trailing_commas( $content ) {
        $length    = strlen( $content );
        $out       = '';
        $in_string = false;
        $escaped   = false;

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $content[ $i ];

            if ( $in_string ) {
                $out .= $char;

                if ( $escaped ) {
                    $escaped = false;
                } elseif ( '\\' === $char ) {
                    $escaped = true;
                } elseif ( '"' === $char ) {
                    $in_string = false;
                }

                continue;
            }

            if ( '"' === $char ) {
                $in_string = true;
                $out      .= $char;
                continue;
            }

            if ( ',' === $char ) {
                // Look past whitespace only. A comma before anything else, a value
                // or another comma, is either legal or a corruption this must not
                // guess at.
                $next = $i + 1 + strspn( $content, " \t\n\r\v\f", $i + 1 );

                if ( $next < $length && ( ']' === $content[ $next ] || '}' === $content[ $next ] ) ) {
                    continue;
                }
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Remove closing brackets that never opened.
     *
     * Production returned a valid JSON string followed by a stray bracket, for
     * example a quoted title ending in a bracket that nothing had opened. Strict
     * decoding fails on the trailing data, and the wrap-in-brackets fallback then
     * produces a doubled bracket that fails as well, so the chunk was lost twice
     * over.
     *
     * This only ever removes. It must never add a missing closing bracket, and that
     * restriction is the point rather than an omission: an answer short of its
     * closing bracket is a truncated answer whose final element is cut mid word.
     * Supplying the bracket would hand that fragment to the placeholder guard,
     * where a fragment carrying no placeholders passes and gets stored. Truncation
     * already has a correct handler in TRP_LLM_Chunk_Runner's rung ladder, reached
     * through was_truncated(), and this must not compete with it.
     *
     * @param string $content Raw content.
     *
     * @return string
     */
    private static function trim_unbalanced_brackets( $content ) {
        $length    = strlen( $content );
        $depth     = 0;
        $in_string = false;
        $escaped   = false;
        $cut       = $length;

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $content[ $i ];

            if ( $in_string ) {
                if ( $escaped ) {
                    $escaped = false;
                } elseif ( '\\' === $char ) {
                    $escaped = true;
                } elseif ( '"' === $char ) {
                    $in_string = false;
                }

                continue;
            }

            if ( '"' === $char ) {
                $in_string = true;
                continue;
            }

            if ( '[' === $char || '{' === $char ) {
                $depth++;
                continue;
            }

            if ( ']' === $char || '}' === $char ) {
                $depth--;

                if ( $depth < 0 ) {
                    // Everything from here on closes something that was never
                    // opened, so the document ends at the previous character.
                    $cut = $i;
                    break;
                }
            }
        }

        return $cut === $length ? $content : rtrim( substr( $content, 0, $cut ) );
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
