<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_Anthropic_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://api.anthropic.com/v1/messages';

    /**
     * Engine slug, as stored in the translation-engine setting.
     */
    const ENGINE = 'anthropic';

    /**
     * Model used when the setting was never saved or was saved empty.
     *
     * The settings form, the JavaScript fallback list and this class each
     * used to carry their own copy of this value, and they had drifted:
     * the form offered one model as selected while an unset option billed
     * a different and more expensive one. The other two now read this.
     */
    const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';

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
            'model'      => $model,
            'max_tokens' => TRP_LLM_Request_Shape::max_output_tokens( $strings_json, $context ),
            'system'     => $prompts['system'],
            'messages'   => array(
                array( 'role' => 'user', 'content' => $prompts['user'] ),
            ),
        );

        $body = apply_filters( 'trp_llm_request_body', $body, $context );

        // No trp_llm_request_args filter here. WordPress fires http_request_args
        // on this array inside wp_remote_post() a moment later, with the URL, so
        // a second extension point for the same array would only give a site two
        // places to do one thing.
        return wp_remote_post( $this->api_url, array(
            'method'  => 'POST',
            'timeout' => TRP_LLM_Request_Retry::request_timeout(),
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
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
        return isset( $this->settings['trp_machine_translation_settings']['anthropic-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['anthropic-api-key']
            : false;
    }

    /**
     * Cache key for one account's model list.
     *
     * Keyed by the API key, because which catalogue an account can reach
     * depends on the account. Unchanged in shape, extracted so a contract can assert it
     * without an HTTP call.
     *
     * @param string $api_key API key the list will be fetched with.
     *
     * @return string
     */
    public static function models_transient_key( $api_key ) {
        return 'trp_anthropic_models_' . md5( (string) $api_key );
    }

    public static function get_available_models( $api_key, $force_refresh = false ) {
        if ( empty( $api_key ) ) {
            return array( 'error' => __( 'API key is required.', 'translatepress-llm-engines' ) );
        }

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

        $response = wp_remote_get( 'https://api.anthropic.com/v1/models', array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
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
            return array( 'error' => __( 'Invalid response from Anthropic API.', 'translatepress-llm-engines' ) );
        }

        $pricing = self::get_anthropic_pricing();
        $models = array();
        $preferred_order = array( 'claude-sonnet-4', 'claude-3-5-sonnet', 'claude-3-5-haiku', 'claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku' );

        foreach ( $body['data'] as $model ) {
            $model_id = $model['id'];
            $display_name = isset( $model['display_name'] ) ? $model['display_name'] : $model_id;

            $price_str = self::get_price_for_model( $model_id, $pricing );
            if ( $price_str ) {
                $display_name .= ' - ' . $price_str;
            }

            if ( strpos( $model_id, 'claude-3-5-haiku' ) === 0 ) {
                $display_name .= ' ★';
            }

            $models[ $model_id ] = $display_name;
        }

        uksort( $models, function( $a, $b ) use ( $preferred_order ) {
            $a_priority = PHP_INT_MAX;
            $b_priority = PHP_INT_MAX;

            foreach ( $preferred_order as $idx => $prefix ) {
                if ( strpos( $a, $prefix ) === 0 && $a_priority === PHP_INT_MAX ) {
                    $a_priority = $idx;
                }
                if ( strpos( $b, $prefix ) === 0 && $b_priority === PHP_INT_MAX ) {
                    $b_priority = $idx;
                }
            }

            if ( $a_priority !== $b_priority ) {
                return $a_priority - $b_priority;
            }
            return strcmp( $a, $b );
        } );

        set_transient( $transient_key, $models, DAY_IN_SECONDS );

        return $models;
    }

    private static function get_anthropic_pricing() {
        return array(
            'claude-3-5-haiku'  => array( 'input' => 0.80, 'output' => 4.00 ),
            'claude-3-5-sonnet' => array( 'input' => 3.00, 'output' => 15.00 ),
            'claude-sonnet-4'   => array( 'input' => 3.00, 'output' => 15.00 ),
            'claude-3-opus'     => array( 'input' => 15.00, 'output' => 75.00 ),
            'claude-3-sonnet'   => array( 'input' => 3.00, 'output' => 15.00 ),
            'claude-3-haiku'    => array( 'input' => 0.25, 'output' => 1.25 ),
        );
    }

    private static function get_price_for_model( $model_id, $pricing ) {
        foreach ( $pricing as $key => $prices ) {
            if ( strpos( $model_id, $key ) === 0 ) {
                return sprintf( '$%.2f/1M in, $%.2f/1M out', $prices['input'], $prices['output'] );
            }
        }
        return '';
    }

    private function get_model() {
        $model = isset( $this->settings['trp_machine_translation_settings']['anthropic-model'] )
            ? trim( (string) $this->settings['trp_machine_translation_settings']['anthropic-model'] )
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
        return wp_remote_get( 'https://api.anthropic.com/v1/models', array(
            'timeout' => 15,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
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
                $return_message = __( 'Please enter your Anthropic API key.', 'translatepress-llm-engines' );
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
                return __( 'Invalid API key. Please check your Anthropic API key.', 'translatepress-llm-engines' );
            case 429:
                return __( 'Rate limit exceeded. Please try again later.', 'translatepress-llm-engines' );
            case 500:
            case 503:
                return __( 'Anthropic service temporarily unavailable. Please try again later.', 'translatepress-llm-engines' );
            default:
                return sprintf(
                    __( 'Anthropic API error (code %d): %s', 'translatepress-llm-engines' ),
                    $code,
                    $api_error
                );
        }
    }
}
