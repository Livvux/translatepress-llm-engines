<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_Anthropic_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://api.anthropic.com/v1/messages';

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
            'model'      => $model,
            'max_tokens' => 4096,
            'system'     => $system_prompt,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $user_prompt ),
            ),
        );

        $response = wp_remote_post( $this->api_url, array(
            'method'  => 'POST',
            'timeout' => 60,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
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
        $chunk_size = apply_filters( 'trp_anthropic_chunk_size', 25 );
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

                if ( isset( $body['content'][0]['text'] ) ) {
                    $content = $body['content'][0]['text'];
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
        return isset( $this->settings['trp_machine_translation_settings']['anthropic-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['anthropic-api-key']
            : false;
    }

    public static function get_available_models( $api_key ) {
        if ( empty( $api_key ) ) {
            return array( 'error' => __( 'API key is required.', 'translatepress-llm-engines' ) );
        }

        $transient_key = 'trp_anthropic_models_' . md5( $api_key );
        $cached_models = get_transient( $transient_key );

        if ( false !== $cached_models ) {
            return $cached_models;
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
        return isset( $this->settings['trp_machine_translation_settings']['anthropic-model'] )
            ? $this->settings['trp_machine_translation_settings']['anthropic-model']
            : 'claude-3-5-sonnet-20241022';
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

        if ( 'anthropic' === $translation_engine && 'yes' === $this->settings['trp_machine_translation_settings']['machine-translation'] ) {
            if ( isset( $this->correct_api_key ) && $this->correct_api_key !== null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = __( 'Please enter your Anthropic API key.', 'translatepress-llm-engines' );
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
