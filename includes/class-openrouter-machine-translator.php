<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_OpenRouter_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://openrouter.ai/api/v1/chat/completions';

    public function send_request( $source_language, $target_language, $strings_array ) {
        $model = $this->get_model();
        $api_key = $this->get_api_key();

        $strings_json = wp_json_encode( array_values( $strings_array ), JSON_UNESCAPED_UNICODE );

        $system_prompt = "You are a professional translator. Translate the given texts from {$source_language} to {$target_language}. " .
                         "Maintain the original meaning, tone, and formatting. " .
                         "Preserve any HTML tags, placeholders like %s, %d, or {{variables}}. " .
                         "Return ONLY a JSON array with the translated strings in the same order as the input. " .
                         "Do not include any explanations or additional text.";

        $user_prompt = "Translate these texts to {$target_language}:\n{$strings_json}";

        $body = array(
            'model'    => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => $system_prompt ),
                array( 'role' => 'user', 'content' => $user_prompt ),
            ),
            'temperature' => 0.1,
        );

        $response = wp_remote_post( $this->api_url, array(
            'method'  => 'POST',
            'timeout' => 60,
            'headers' => array(
                'Content-Type'     => 'application/json',
                'Authorization'    => 'Bearer ' . $api_key,
                'HTTP-Referer'     => home_url(),
                'X-Title'          => get_bloginfo( 'name' ),
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        return $response;
    }

    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( $source_language_code === null ) {
            $source_language_code = $this->settings['default-language'];
        }

        if ( empty( $new_strings ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
            return array();
        }

        $source_language = $this->get_language_name( $source_language_code );
        $target_language = $this->get_language_name( $target_language_code );

        $translated_strings = array();
        $chunk_size = apply_filters( 'trp_openrouter_chunk_size', 25 );
        $new_strings_chunks = array_chunk( $new_strings, $chunk_size, true );

        foreach ( $new_strings_chunks as $new_strings_chunk ) {
            $response = $this->send_request( $source_language, $target_language, $new_strings_chunk );

            $this->machine_translator_logger->log( array(
                'strings'     => serialize( $new_strings_chunk ),
                'response'    => serialize( $response ),
                'lang_source' => $source_language,
                'lang_target' => $target_language,
            ) );

            if ( is_array( $response ) && ! is_wp_error( $response ) &&
                 isset( $response['response']['code'] ) && $response['response']['code'] === 200 ) {

                $body = json_decode( $response['body'], true );

                if ( isset( $body['choices'][0]['message']['content'] ) ) {
                    $content = $body['choices'][0]['message']['content'];
                    $translations = $this->parse_translation_response( $content );

                    if ( ! empty( $translations ) && count( $translations ) === count( $new_strings_chunk ) ) {
                        $this->machine_translator_logger->count_towards_quota( $new_strings_chunk );

                        $i = 0;
                        foreach ( $new_strings_chunk as $key => $old_string ) {
                            $translated_strings[ $key ] = isset( $translations[ $i ] ) ? $translations[ $i ] : $old_string;
                            $i++;
                        }
                    }
                }

                if ( $this->machine_translator_logger->quota_exceeded() ) {
                    break;
                }
            }
        }

        return $translated_strings;
    }

    private function parse_translation_response( $content ) {
        $content = trim( $content );

        if ( strpos( $content, '```json' ) !== false ) {
            $content = preg_replace( '/```json\s*/', '', $content );
            $content = preg_replace( '/```\s*$/', '', $content );
        } elseif ( strpos( $content, '```' ) !== false ) {
            $content = preg_replace( '/```\s*/', '', $content );
        }

        $translations = json_decode( trim( $content ), true );

        if ( json_last_error() === JSON_ERROR_NONE && is_array( $translations ) ) {
            return $translations;
        }

        return array();
    }

    private function get_language_name( $language_code ) {
        $language_names = array(
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

        if ( isset( $language_names[ $language_code ] ) ) {
            return $language_names[ $language_code ];
        }

        $iso_code = explode( '_', $language_code )[0];
        return ucfirst( $iso_code );
    }

    public function test_request() {
        return $this->send_request( 'English', 'Spanish', array( 'Hello, how are you?' ) );
    }

    public function get_api_key() {
        return isset( $this->settings['trp_machine_translation_settings']['openrouter-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['openrouter-api-key']
            : false;
    }

    public static function get_available_models( $api_key ) {
        $transient_key = 'trp_openrouter_models';
        $cached_models = get_transient( $transient_key );

        if ( false !== $cached_models ) {
            return $cached_models;
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
        return isset( $this->settings['trp_machine_translation_settings']['openrouter-model'] )
            ? $this->settings['trp_machine_translation_settings']['openrouter-model']
            : 'anthropic/claude-3.5-sonnet';
    }

    public function get_supported_languages() {
        return array(
            'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'ru', 'zh', 'ja', 'ko',
            'ar', 'tr', 'pl', 'sv', 'da', 'fi', 'no', 'cs', 'el', 'hu', 'ro',
            'uk', 'he', 'th', 'vi', 'id', 'ms', 'hi', 'bn', 'ta', 'te', 'mr',
            'gu', 'kn', 'ml', 'pa', 'ur', 'fa', 'af', 'sq', 'am', 'hy', 'az',
            'eu', 'be', 'bg', 'ca', 'hr', 'et', 'tl', 'gl', 'ka', 'is', 'lv',
            'lt', 'mk', 'mt', 'mn', 'ne', 'sr', 'sk', 'sl', 'sw', 'cy', 'yi',
        );
    }

    public function get_engine_specific_language_codes( $languages ) {
        return $this->trp_languages->get_iso_codes( $languages );
    }

    public function check_api_key_validity() {
        $api_key = $this->get_api_key();
        $translation_engine = $this->settings['trp_machine_translation_settings']['translation-engine'];
        $is_error = false;
        $return_message = '';

        if ( 'openrouter' === $translation_engine && 'yes' === $this->settings['trp_machine_translation_settings']['machine-translation'] ) {
            if ( isset( $this->correct_api_key ) && $this->correct_api_key !== null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = __( 'Please enter your OpenRouter API key.', 'translatepress-llm-engines' );
            } else {
                $response = $this->test_request();
                $code = wp_remote_retrieve_response_code( $response );

                if ( 200 !== $code ) {
                    $is_error = true;
                    $return_message = $this->get_error_message( $code, $response );
                }
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
