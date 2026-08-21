<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_OpenRouter_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Engine slug, as stored in the translation-engine setting.
     */
    const ENGINE = 'openrouter';

    /**
     * Model used when the setting was never saved or was saved empty.
     *
     * The settings form, the JavaScript fallback list and this class each
     * used to carry their own copy of this value, and they had drifted:
     * the form offered one model as selected while an unset option billed
     * a different and more expensive one. The other two now read this.
     */
    const DEFAULT_MODEL = 'deepseek/deepseek-v4-flash';

    public function send_request( $source_language, $target_language, $strings_array, $source_code = '', $target_code = '', $attempt = 0 ) {
        $model   = $this->get_model();
        $api_key = $this->get_api_key();

        $strings_json = wp_json_encode( array_values( $strings_array ), JSON_UNESCAPED_UNICODE );

        $context = TRP_LLM_Request_Shape::context(
            self::ENGINE,
            $model,
            $source_language,
            $target_language,
            $source_code,
            $target_code,
            $strings_array,
            $attempt
        );

        $prompts = TRP_LLM_Request_Shape::prompts( $context, $strings_json );

        $body = array(
            'model'       => $model,
            'messages'    => array(
                array( 'role' => 'system', 'content' => $prompts['system'] ),
                array( 'role' => 'user', 'content' => $prompts['user'] ),
            ),
            'temperature' => 0.1,
            'max_tokens'  => TRP_LLM_Request_Shape::max_output_tokens( $strings_json, $context ),
            // A reasoning model spends its output budget thinking and then
            // returns an empty content string, which is indistinguishable from
            // every other failure once it reaches the parser.
            'reasoning'   => array( 'enabled' => false ),
            // The only real cost signal available.
            'usage'       => array( 'include' => true ),
            'provider'    => array(
                'data_collection' => 'deny',
                'allow_fallbacks' => true,
            ),
        );

        // Off by default rather than json_object. A downstream filter on this
        // site asks the model for a bare JSON array, and OpenAI compatible
        // json_object mode requires an object, so switching this on without
        // changing that instruction first would put two contradictory demands in
        // one request.
        $response_format = TRP_LLM_Request_Shape::optional( 'trp_llm_response_format', $context );

        if ( array() !== $response_format ) {
            $body['response_format'] = $response_format;
        }

        // Empty by default on purpose. A silent fallback to a second model
        // changes both cost and voice with no trace in the answer.
        $fallbacks = TRP_LLM_Request_Shape::optional( 'trp_llm_openrouter_fallback_models', $context );

        if ( array() !== $fallbacks ) {
            $body['models'] = array_values( $fallbacks );
        }

        $body = apply_filters( 'trp_llm_request_body', $body, $context );

        // No trp_llm_request_args filter here. WordPress fires http_request_args
        // on this array inside wp_remote_post() a moment later, with the URL, so
        // a second extension point for the same array would only give a site two
        // places to do one thing.
        return wp_remote_post( $this->api_url, array(
            'method'  => 'POST',
            'timeout' => TRP_LLM_Request_Retry::request_timeout(),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo( 'name' ),
            ),
            'body'    => wp_json_encode( $body ),
        ) );
    }

    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( $source_language_code === null ) {
            $source_language_code = $this->settings['default-language'];
        }

        if ( empty( $new_strings ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
            return array();
        }

        $partitioned = TRP_LLM_Translation_Skiplist::partition( $new_strings );
        $new_strings = $partitioned['send'];

        // A skipped string is returned as itself, which is the vendor's own
        // pattern for its skips. Dropping the key instead would leave the row at
        // status 0, so the render layer would re-harvest and re-offer it on every
        // future render with no terminating condition.
        $translated_strings = $partitioned['skip'];

        if ( empty( $new_strings ) ) {
            return $translated_strings;
        }

        $source_language = $this->get_language_name( $source_language_code );
        $target_language = $this->get_language_name( $target_language_code );

        // A cooldown is the terminating condition this loop never had. Without it
        // an expired key, a spent balance or an invalid model produces one doomed
        // call per chunk per render, on every page carrying an untranslated
        // string, indefinitely.
        if ( '' !== TRP_LLM_Engine_Cooldown::active( self::ENGINE ) ) {
            if ( TRP_LLM_Engine_Cooldown::note_skip( self::ENGINE ) ) {
                TRP_LLM_Response_Normalizer::log_chunk_failure(
                    self::ENGINE,
                    'cooldown',
                    count( $new_strings ),
                    0,
                    ''
                );
            }

            return $translated_strings;
        }

        $mt_settings = isset( $this->settings['trp_machine_translation_settings'] )
            ? $this->settings['trp_machine_translation_settings']
            : array();

        $chunks = array_chunk( $new_strings, TRP_LLM_Request_Shape::chunk_size( self::ENGINE ), true );

        foreach ( $chunks as $chunk ) {
            $context = TRP_LLM_Request_Shape::context(
                self::ENGINE,
                $this->get_model(),
                $source_language,
                $target_language,
                $source_language_code,
                $target_language_code,
                $chunk
            );

            $translated = TRP_LLM_Chunk_Runner::run(
                $chunk,
                $context,
                $this->machine_translator_logger,
                $mt_settings,
                function ( $part, $attempt ) use ( $source_language, $target_language, $source_language_code, $target_language_code ) {
                    return $this->send_request(
                        $source_language,
                        $target_language,
                        $part,
                        $source_language_code,
                        $target_language_code,
                        $attempt
                    );
                }
            );

            $translated_strings += $translated;

            // Only a chunk that produced nothing can have started a cooldown, so
            // the happy path never pays for this read.
            if ( array() === $translated && '' !== TRP_LLM_Engine_Cooldown::active( self::ENGINE ) ) {
                break;
            }

            if ( $this->machine_translator_logger->quota_exceeded() ) {
                break;
            }
        }

        return $translated_strings;
    }

    /**
     * Strings this engine sends in one request.
     *
     * The vendor chunks by this before saving each chunk to the database, so a
     * value smaller than what this class really sends means several paid calls
     * happen before the first save, and an aborted page load re-sends and
     * re-bills all of them.
     *
     * @return int
     */
    public function get_chunk_size() {
        return TRP_LLM_Request_Shape::chunk_size( self::ENGINE );
    }

    private function get_language_name( $language_code ) {
        return TRP_LLM_Request_Shape::language_name( $language_code );
    }

    public function test_request() {
        return $this->send_request( 'English', 'Spanish', array( 'Hello, how are you?' ) );
    }

    public function get_api_key() {
        return isset( $this->settings['trp_machine_translation_settings']['openrouter-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['openrouter-api-key']
            : false;
    }

    /**
     * Cache key for one account's model list.
     *
     * Keyed by the API key, because which catalogue an account can reach
     * depends on the account. This list used to be cached under one shared name with
     * no key in it, so pasting a second key kept serving the first key's
     * catalogue for a full day.
     *
     * @param string $api_key API key the list will be fetched with.
     *
     * @return string
     */
    public static function models_transient_key( $api_key ) {
        return 'trp_openrouter_models_' . md5( (string) $api_key );
    }

    public static function get_available_models( $api_key, $force_refresh = false ) {
        $transient_key = self::models_transient_key( $api_key );

        // The settings screen ships a Refresh Models button whose request
        // always carried this flag. Nothing read it, so the button could not
        // do anything for the 24 hours the cache lives.
        if ( $force_refresh ) {
            delete_transient( $transient_key );
        } else {
            $cached_models = get_transient( $transient_key );

            if ( false !== $cached_models ) {
                return $cached_models;
            }
        }

        $headers = array(
            'Content-Type' => 'application/json',
        );

        if ( ! empty( $api_key ) ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $response = wp_remote_get( 'https://openrouter.ai/api/v1/models', array(
            'timeout' => 30,
            'headers' => $headers,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Failed to fetch models.', 'translatepress-llm-engines' );
            return array( 'error' => $error_msg );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            return array( 'error' => __( 'Invalid response from OpenRouter API.', 'translatepress-llm-engines' ) );
        }

        $models = array();
        $preferred_providers = array( 'anthropic', 'openai', 'google', 'meta-llama', 'mistralai', 'deepseek' );

        foreach ( $body['data'] as $model ) {
            $model_id = $model['id'];
            $model_name = isset( $model['name'] ) ? $model['name'] : $model_id;

            $provider = explode( '/', $model_id )[0];
            if ( ! in_array( $provider, $preferred_providers, true ) ) {
                continue;
            }

            $context_length = isset( $model['context_length'] ) ? $model['context_length'] : 0;
            if ( $context_length < 4000 ) {
                continue;
            }

            $pricing = isset( $model['pricing'] ) ? $model['pricing'] : array();
            $prompt_price = isset( $pricing['prompt'] ) ? floatval( $pricing['prompt'] ) : 0;
            $completion_price = isset( $pricing['completion'] ) ? floatval( $pricing['completion'] ) : 0;

            $models[] = array(
                'id'               => $model_id,
                'name'             => $model_name,
                'provider'         => $provider,
                'prompt_price'     => $prompt_price,
                'completion_price' => $completion_price,
            );
        }

        usort( $models, function( $a, $b ) use ( $preferred_providers ) {
            $a_provider_idx = array_search( $a['provider'], $preferred_providers, true );
            $b_provider_idx = array_search( $b['provider'], $preferred_providers, true );

            if ( $a_provider_idx !== $b_provider_idx ) {
                return $a_provider_idx - $b_provider_idx;
            }

            return $a['prompt_price'] - $b['prompt_price'];
        } );

        $result = array();
        foreach ( $models as $model ) {
            $price_str = self::format_openrouter_price( $model['prompt_price'], $model['completion_price'] );
            $label = $model['name'];
            if ( $price_str ) {
                $label .= ' - ' . $price_str;
            }
            $result[ $model['id'] ] = $label;
        }

        set_transient( $transient_key, $result, DAY_IN_SECONDS );

        return $result;
    }

    private static function format_openrouter_price( $prompt_price, $completion_price ) {
        if ( $prompt_price <= 0 && $completion_price <= 0 ) {
            return 'Free';
        }

        $prompt_per_1m = $prompt_price * 1000000;
        $completion_per_1m = $completion_price * 1000000;

        return sprintf( '$%.2f/1M in, $%.2f/1M out', $prompt_per_1m, $completion_per_1m );
    }

    private function get_model() {
        $model = isset( $this->settings['trp_machine_translation_settings']['openrouter-model'] )
            ? trim( (string) $this->settings['trp_machine_translation_settings']['openrouter-model'] )
            : '';

        // isset() alone let a saved but empty value through as an empty
        // model name, which the provider answers with a 400 that left no
        // trace anywhere before TRP_LLM_Http_Failure_Log existed.
        return '' !== $model ? $model : static::DEFAULT_MODEL;
    }

    public function get_supported_languages() {
        return TRP_LLM_Request_Shape::SUPPORTED_LANGUAGES;
    }

    public function get_engine_specific_language_codes( $languages ) {
        return $this->trp_languages->get_iso_codes( $languages );
    }

    /**
     * Ask the provider whether this key is usable, without buying anything.
     *
     * @param string $api_key Key to check.
     *
     * @return array|WP_Error
     */
    private function validate_key_request( $api_key ) {
        return wp_remote_get( 'https://openrouter.ai/api/v1/key', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
            ),
        ) );
    }

    public function check_api_key_validity() {
        $api_key = $this->get_api_key();
        $translation_engine = $this->settings['trp_machine_translation_settings']['translation-engine'];
        $is_error = false;
        $return_message = '';

        if ( self::ENGINE === $translation_engine && 'yes' === $this->settings['trp_machine_translation_settings']['machine-translation'] ) {
            if ( isset( $this->correct_api_key ) && $this->correct_api_key !== null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = __( 'Please enter your OpenRouter API key.', 'translatepress-llm-engines' );
            } else {
                // Free probe plus a short lived verdict. This used to call
                // test_request(), a real translation, once per engine panel on
                // every render of the settings screen.
                $verdict = TRP_LLM_Key_Verdict::remember( self::ENGINE, $api_key, function () use ( $api_key ) {
                    $response = $this->validate_key_request( $api_key );
                    $code     = (int) wp_remote_retrieve_response_code( $response );

                    return array(
                        'error'   => 200 !== $code,
                        'message' => 200 !== $code ? $this->get_error_message( $code, $response ) : '',
                    );
                } );

                $is_error       = $verdict['error'];
                $return_message = $verdict['message'];
            }

            $this->correct_api_key = array(
                'message' => $return_message,
                'error'   => $is_error,
            );
        }

        return array(
            'message' => $return_message,
            'error'   => $is_error,
        );
    }

    private function get_error_message( $code, $response ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $api_error = isset( $body['error']['message'] ) ? $body['error']['message'] : '';

        switch ( $code ) {
            case 401:
                return __( 'Invalid API key. Please check your OpenRouter API key.', 'translatepress-llm-engines' );
            case 402:
                return __( 'Insufficient credits. Please add credits to your OpenRouter account.', 'translatepress-llm-engines' );
            case 429:
                return __( 'Rate limit exceeded. Please try again later.', 'translatepress-llm-engines' );
            case 500:
            case 503:
                return __( 'OpenRouter service temporarily unavailable. Please try again later.', 'translatepress-llm-engines' );
            default:
                return sprintf(
                    __( 'OpenRouter API error (code %d): %s', 'translatepress-llm-engines' ),
                    $code,
                    $api_error
                );
        }
    }
}
