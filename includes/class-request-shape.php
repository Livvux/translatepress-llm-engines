<?php
/**
 * The parts of an LLM request that are the same whichever provider answers it.
 *
 * Three things live here.
 *
 * The filter surface. The engines built their prompt and their body inline with
 * no way in, which is why a site that needed one extra sentence in the system
 * prompt had to intercept the raw HTTP body of an outgoing request and match on
 * the prompt's opening words to recognise it. That works and it is fragile: it
 * breaks silently the day the wording changes. A filter is the same capability
 * with none of the guessing, and the context handed to it carries the language
 * codes so a rule can key on de_DE rather than on the English word German.
 *
 * The output ceiling. Omitting max_tokens is not free on every provider, because
 * some validate the account balance against the model's advertised output
 * ceiling rather than against what the request will really use. A flat value is
 * not right either: 4096 tokens truncated four batches on 2026-08-17, and a
 * truncated answer is a whole chunk lost, paid for and retried. So the ceiling
 * is derived from how much text was actually sent.
 *
 * The finish reason. A truncated answer and a malformed answer look identical
 * once JSON parsing has failed, but they need opposite responses: one should be
 * split and re-sent, the other should not be sent again at all.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Request_Shape {

    /**
     * Never ask for fewer output tokens than this.
     */
    const MIN_OUTPUT_TOKENS = 1024;

    /**
     * Never ask for more than this from one chunk.
     */
    const MAX_OUTPUT_TOKENS = 8192;

    /**
     * Everything a filter needs to know about the request being built.
     *
     * @param string $engine      Engine slug.
     * @param string $model       Model the request will ask for.
     * @param string $source_name Source language in English, such as 'English'.
     * @param string $target_name Target language in English, such as 'German'.
     * @param string $source_code Source locale, such as 'en_US'.
     * @param string $target_code Target locale, such as 'de_DE'.
     * @param array  $strings     Strings being sent, positional.
     * @param int    $attempt     0 for a first send, 1 after a truncated answer.
     *
     * @return array
     */
    public static function context( $engine, $model, $source_name, $target_name, $source_code, $target_code, $strings, $attempt = 0 ) {
        return array(
            'attempt'              => (int) $attempt,
            'engine'               => (string) $engine,
            'model'                => (string) $model,
            'source_language'      => (string) $source_name,
            'target_language'      => (string) $target_name,
            'source_language_code' => (string) $source_code,
            'target_language_code' => (string) $target_code,
            'strings'              => array_values( (array) $strings ),
        );
    }

    /** Build exactly the body used by every provider and by per-string identity. */
    public static function body( array $context ) {
        $json = wp_json_encode( array_values( $context['strings'] ), JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            return new WP_Error( 'trp_llm_invalid_encoding', 'Unable to encode translation strings.' );
        }
        $prompts = self::prompts( $context, $json );
        $tokens = self::max_output_tokens( $json, $context );
        $body = array(
            'model' => $context['model'],
            'messages' => array(
                array( 'role' => 'system', 'content' => $prompts['system'] ),
                array( 'role' => 'user', 'content' => $prompts['user'] ),
            ),
            'max_tokens' => $tokens,
            'temperature' => 0.1,
        );
        if ( 'anthropic' === $context['engine'] ) {
            $body['system'] = $prompts['system'];
            $body['messages'] = array( $body['messages'][1] );
            unset( $body['temperature'] );
        } elseif ( 'openai' === $context['engine'] ) {
            $parameters = TRP_LLM_Model_Catalog::parameters( $context['model'], $tokens );
            if ( is_wp_error( $parameters ) ) {
                return $parameters;
            }
            unset( $body['max_tokens'], $body['temperature'] );
            $body += $parameters;
        }
        if ( 'anthropic' !== $context['engine'] ) {
            $format = self::optional( 'trp_llm_response_format', $context );
            if ( $format ) {
                $body['response_format'] = $format;
            }
        }
        if ( 'openrouter' === $context['engine'] ) {
            $body['reasoning'] = array( 'enabled' => false );
            $body['usage'] = array( 'include' => true );
            $body['provider'] = array( 'data_collection' => 'deny', 'allow_fallbacks' => true );
            $fallbacks = self::optional( 'trp_llm_openrouter_fallback_models', $context );
            if ( $fallbacks ) {
                $body['models'] = array_values( $fallbacks );
            }
        }
        $body = apply_filters( 'trp_llm_request_body', $body, $context );
        if ( ! is_array( $body ) || ! empty( $body['stream'] ) ) {
            return new WP_Error( 'trp_llm_invalid_body', 'Expected a non-streaming translation request body.' );
        }
        return 'openai' === $context['engine'] ? TRP_LLM_Model_Catalog::validate( $body ) : $body;
    }

    /**
     * Output ceiling for a chunk.
     *
     * Derived from the text the chunk carries, with generous headroom: a
     * translation is usually close to the length of its source, but a language
     * with longer words and the JSON scaffolding around the list both add to it,
     * and the cost of guessing low is losing and re-billing the whole chunk.
     *
     * The attempt matters, and this is the part that was wrong before. A chunk
     * that truncated used to be halved, which halves the payload, which halves
     * this ceiling, which leaves the tokens per source byte exactly where they
     * were. Halving changed the size of the request without changing the
     * constraint that broke it, so the second attempt was as doomed as the first
     * for anything under the clamp. On a retry the ceiling goes to the maximum
     * instead, which is the only move that raises tokens per byte for the same
     * text. Halving is what comes after that, and only then does it help,
     * because at a fixed maximum ceiling half the payload really is double the
     * room.
     *
     * @param string $payload JSON of the strings being sent.
     * @param array  $context Request context.
     *
     * @return int
     */
    public static function max_output_tokens( $payload, $context ) {
        $estimate = empty( $context['attempt'] )
            ? (int) ( strlen( (string) $payload ) * 1.6 )
            : self::MAX_OUTPUT_TOKENS;

        $bounded = max( self::MIN_OUTPUT_TOKENS, min( self::MAX_OUTPUT_TOKENS, $estimate ) );

        return (int) apply_filters( 'trp_llm_max_output_tokens', $bounded, $context );
    }

    /**
     * The assistant message, in the two shapes the providers use.
     *
     * The sibling finish_reason() already absorbed exactly this difference. The
     * content path was the one place each engine still open coded it, and it was
     * the last thing keeping four otherwise identical chunk loops apart.
     *
     * @param array $body Decoded response body.
     *
     * @return string|null Null when the answer carried no message.
     */
    public static function message_content( $body ) {
        if ( ! is_array( $body ) ) {
            return null;
        }

        if ( isset( $body['choices'] ) ) {
            $content = $body['choices'][0]['message']['content'] ?? null;
            return is_string( $content ) ? $content : null;
        }
        if ( ! isset( $body['content'] ) || ! is_array( $body['content'] ) ) {
            return null;
        }
        $text = array();
        foreach ( $body['content'] as $block ) {
            if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
                return null;
            }
            if ( 'text' === $block['type'] && isset( $block['text'] ) && is_string( $block['text'] ) ) {
                $text[] = $block['text'];
            } elseif ( ! in_array( $block['type'], array( 'thinking', 'redacted_thinking' ), true ) ) {
                // Never mistake tool output or unknown block types for translation text.
                return null;
            }
        }
        return $text ? implode( '', $text ) : null;
    }

    /**
     * The system and user prompt for a request.
     *
     * One copy of a string that had four. The opening sentence is load bearing:
     * a site filter downstream may recognise this request by it, so reword it
     * only together with whatever matches on it. Having had four copies of a
     * string with that property was the risk this consolidation removes.
     *
     * @param array  $context      Request context.
     * @param string $strings_json JSON of the strings being sent.
     *
     * @return array{system:string, user:string}
     */
    public static function prompts( $context, $strings_json ) {
        $source = $context['source_language'];
        $target = $context['target_language'];

        $system = "You are a professional translator. Translate the given texts from {$source} to {$target}. " .
                  "Maintain the original meaning, tone, and formatting. " .
                  "Preserve any HTML tags, placeholders like %s, %d, or {{variables}}. " .
                  "Return ONLY a JSON array with the translated strings in the same order as the input. " .
                  "Do not include any explanations or additional text.";

        $user = "Translate these texts to {$target}:\n{$strings_json}";

        return array(
            'system' => (string) apply_filters( 'trp_llm_system_prompt', $system, $context ),
            'user'   => (string) apply_filters( 'trp_llm_user_prompt', $user, $context ),
        );
    }

    /**
     * Strings one engine sends in a single request.
     *
     * The generic filter runs first so a site can set one size for every engine,
     * the engine specific one keeps working on top of it.
     *
     * @param string $engine Engine slug.
     *
     * @return int
     */
    public static function chunk_size( $engine ) {
        $size = (int) apply_filters( 'trp_llm_chunk_size', 25, $engine );

        return max( 1, (int) apply_filters( 'trp_' . $engine . '_chunk_size', $size ) );
    }

    /**
     * Whether an optional body field should be added.
     *
     * @param string $filter  Filter name.
     * @param array  $context Request context.
     *
     * @return array
     */
    public static function optional( $filter, $context ) {
        $value = apply_filters( $filter, array(), $context );

        return is_array( $value ) ? $value : array();
    }

    /**
     * Why the model stopped, in the two shapes the providers use.
     *
     * @param array $body Decoded response body.
     *
     * @return string Empty string when the answer carried no reason.
     */
    public static function finish_reason( $body ) {
        if ( ! is_array( $body ) ) {
            return '';
        }

        if ( isset( $body['choices'][0]['finish_reason'] ) && is_string( $body['choices'][0]['finish_reason'] ) ) {
            return $body['choices'][0]['finish_reason'];
        }

        if ( isset( $body['stop_reason'] ) && is_string( $body['stop_reason'] ) ) {
            return $body['stop_reason'];
        }

        return '';
    }

    /**
     * Whether the model ran out of room rather than finishing.
     *
     * @param array $body Decoded response body.
     *
     * @return bool
     */
    public static function was_truncated( $body ) {
        return in_array( self::finish_reason( $body ), array( 'length', 'max_tokens', 'model_context_window_exceeded' ), true );
    }

    /** Record immediately and atomically, not in a shared shutdown option. */
    public static function record_cost( $engine, $body ) {
        TRP_LLM_Cost_Ledger::record( $engine, $body );
    }

    /** Compatibility for callers of the previous deferred writer. */
    public static function flush_cost() {
        // Costs are already committed by record_cost().
    }

    /**
     * Locales named the way a prompt should name them.
     *
     * This map used to sit in all four engine classes, byte for byte the same in
     * each. Four locales are being published this month, so the copy that gets
     * forgotten is a matter of when, not whether, and a forgotten copy makes one
     * engine name a language differently from the others inside the prompt.
     *
     * The English names are load bearing beyond legibility. The site's voice
     * mu-plugin keys its German informal address rule on the prompt containing
     * "to German", so de_DE must keep resolving to exactly German.
     */
    const LANGUAGE_NAMES = array(
        'en_US' => 'English',
        'en_GB' => 'English (UK)',
        'de_DE' => 'German',
        'fr_FR' => 'French',
        'es_ES' => 'Spanish',
        'it_IT' => 'Italian',
        'pt_PT' => 'Portuguese',
        'pt_BR' => 'Portuguese (Brazil)',
        'nl_NL' => 'Dutch',
        'ru_RU' => 'Russian',
        'zh_CN' => 'Chinese (Simplified)',
        'zh_TW' => 'Chinese (Traditional)',
        'ja'    => 'Japanese',
        'ko_KR' => 'Korean',
        'ar'    => 'Arabic',
        'tr_TR' => 'Turkish',
        'pl_PL' => 'Polish',
        'sv_SE' => 'Swedish',
        'da_DK' => 'Danish',
        'fi'    => 'Finnish',
        'no_NO' => 'Norwegian',
        'cs_CZ' => 'Czech',
        'el'    => 'Greek',
        'hu_HU' => 'Hungarian',
        'ro_RO' => 'Romanian',
        'uk'    => 'Ukrainian',
        'he_IL' => 'Hebrew',
        'th'    => 'Thai',
        'vi'    => 'Vietnamese',
        'id_ID' => 'Indonesian',
    );

    /**
     * ISO codes every one of these providers will translate into.
     *
     * Also formerly four identical copies.
     */
    const SUPPORTED_LANGUAGES = array(
        'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'ru', 'zh', 'ja', 'ko',
        'ar', 'tr', 'pl', 'sv', 'da', 'fi', 'no', 'cs', 'el', 'hu', 'ro',
        'uk', 'he', 'th', 'vi', 'id', 'ms', 'hi', 'bn', 'ta', 'te', 'mr',
        'gu', 'kn', 'ml', 'pa', 'ur', 'fa', 'af', 'sq', 'am', 'hy', 'az',
        'eu', 'be', 'bg', 'ca', 'hr', 'et', 'tl', 'gl', 'ka', 'is', 'lv',
        'lt', 'mk', 'mt', 'mn', 'ne', 'sr', 'sk', 'sl', 'sw', 'cy', 'yi',
    );

    /**
     * Name a locale for the prompt.
     *
     * An unmapped locale falls back to its capitalised ISO part, which is what
     * the four copies did and is right often enough to be worth keeping.
     *
     * @param string $language_code TranslatePress locale, for example de_DE.
     *
     * @return string
     */
    public static function language_name( $language_code ) {
        if ( isset( self::LANGUAGE_NAMES[ $language_code ] ) ) {
            return self::LANGUAGE_NAMES[ $language_code ];
        }

        return ucfirst( explode( '_', (string) $language_code )[0] );
    }
}
