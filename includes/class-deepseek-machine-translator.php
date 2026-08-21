<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_DeepSeek_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://api.deepseek.com/chat/completions';

    /**
     * Engine slug, as stored in the translation-engine setting.
     */
    const ENGINE = 'deepseek';

    /**
     * Model used when the setting was never saved or was saved empty.
     *
     * The settings form, the JavaScript fallback list and this class each
     * used to carry their own copy of this value, and they had drifted:
     * the form offered one model as selected while an unset option billed
     * a different and more expensive one. The other two now read this.
     */
    const DEFAULT_MODEL = 'deepseek-chat';

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
        );

        $response_format = TRP_LLM_Request_Shape::optional( 'trp_llm_response_format', $context );

        if ( array() !== $response_format ) {
            $body['response_format'] = $response_format;
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
        return isset( $this->settings['trp_machine_translation_settings']['deepseek-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['deepseek-api-key']
            : false;
    }

    private function get_model() {
        $model = isset( $this->settings['trp_machine_translation_settings']['deepseek-model'] )
            ? trim( (string) $this->settings['trp_machine_translation_settings']['deepseek-model'] )
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
        return wp_remote_get( 'https://api.deepseek.com/user/balance', array(
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
                $return_message = __( 'Please enter your DeepSeek API key.', 'translatepress-llm-engines' );
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
                return __( 'Invalid API key. Please check your DeepSeek API key.', 'translatepress-llm-engines' );
            case 402:
                return __( 'Insufficient credits. Please add credits to your DeepSeek account.', 'translatepress-llm-engines' );
            case 429:
                return __( 'Rate limit exceeded. Please try again later.', 'translatepress-llm-engines' );
            case 500:
            case 503:
                return __( 'DeepSeek service temporarily unavailable. Please try again later.', 'translatepress-llm-engines' );
            default:
                return sprintf(
                    __( 'DeepSeek API error (code %d): %s', 'translatepress-llm-engines' ),
                    $code,
                    $api_error
                );
        }
    }
}
