<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_OpenAI_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://api.openai.com/v1/chat/completions';

    public function send_request( $source_language, $target_language, $strings_array ) {
        $model = $this->get_model();
        $api_key = $this->get_api_key();

        $messages = $this->build_translation_messages( $source_language, $target_language, $strings_array );

        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.1,
        );

        $response = wp_remote_post( $this->api_url, array(
            'method'  => 'POST',
            'timeout' => 60,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        return $response;
    }

    private function build_translation_messages( $source_language, $target_language, $strings_array ) {
        $strings_json = wp_json_encode( array_values( $strings_array ), JSON_UNESCAPED_UNICODE );

        $system_prompt = "You are a professional translator. Translate the given texts from {$source_language} to {$target_language}. " .
                         "Maintain the original meaning, tone, and formatting. " .
                         "Preserve any HTML tags, placeholders like %s, %d, or {{variables}}. " .
                         "Return ONLY a JSON array with the translated strings in the same order as the input. " .
                         "Do not include any explanations or additional text.";

        $user_prompt = "Translate these texts to {$target_language}:\n{$strings_json}";

        return array(
            array( 'role' => 'system', 'content' => $system_prompt ),
            array( 'role' => 'user', 'content' => $user_prompt ),
        );
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
        $chunk_size = apply_filters( 'trp_openai_chunk_size', 25 );
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
        return isset( $this->settings['trp_machine_translation_settings']['openai-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['openai-api-key']
            : false;
    }

    public static function get_available_models( $api_key ) {
        if ( empty( $api_key ) ) {
            return array( 'error' => __( 'API key is required.', 'translatepress-llm-engines' ) );
        }

        $transient_key = 'trp_openai_models_' . md5( $api_key );
        $cached_models = get_transient( $transient_key );

        if ( false !== $cached_models ) {
            return $cached_models;
        }

        $response = wp_remote_get( 'https://api.openai.com/v1/models', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
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
            return array( 'error' => __( 'Invalid response from OpenAI API.', 'translatepress-llm-engines' ) );
        }

        $pricing = self::get_openai_pricing();
        $chat_models = array();
        $preferred_models = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo', 'o1-mini', 'o1-preview' );

        foreach ( $body['data'] as $model ) {
            $model_id = $model['id'];
            if ( strpos( $model_id, 'gpt-' ) === 0 || strpos( $model_id, 'o1' ) === 0 ) {
                if ( strpos( $model_id, '-instruct' ) !== false ) {
                    continue;
                }
                if ( preg_match( '/-\d{4}$/', $model_id ) && in_array( preg_replace( '/-\d{4}$/', '', $model_id ), $chat_models, true ) ) {
                    continue;
                }
                $chat_models[] = $model_id;
            }
        }

        usort( $chat_models, function( $a, $b ) use ( $preferred_models ) {
            $a_idx = array_search( $a, $preferred_models, true );
            $b_idx = array_search( $b, $preferred_models, true );

            if ( $a_idx !== false && $b_idx !== false ) {
                return $a_idx - $b_idx;
            }
            if ( $a_idx !== false ) {
                return -1;
            }
            if ( $b_idx !== false ) {
                return 1;
            }
            return strcmp( $a, $b );
        } );

        $models = array();
        foreach ( $chat_models as $model_id ) {
            $label = str_replace( array( 'gpt-', '-' ), array( 'GPT-', ' ' ), $model_id );
            $label = ucwords( $label );

            $price_str = self::get_price_for_model( $model_id, $pricing );
            if ( $price_str ) {
                $label .= ' - ' . $price_str;
            }

            if ( $model_id === 'gpt-4o-mini' ) {
                $label .= ' ★';
            }
            $models[ $model_id ] = $label;
        }

        set_transient( $transient_key, $models, DAY_IN_SECONDS );

        return $models;
    }

    private static function get_openai_pricing() {
        return array(
            'gpt-4o-mini'    => array( 'input' => 0.15, 'output' => 0.60 ),
            'gpt-4o'         => array( 'input' => 2.50, 'output' => 10.00 ),
            'gpt-4-turbo'    => array( 'input' => 10.00, 'output' => 30.00 ),
            'gpt-4'          => array( 'input' => 30.00, 'output' => 60.00 ),
            'gpt-3.5-turbo'  => array( 'input' => 0.50, 'output' => 1.50 ),
            'o1-mini'        => array( 'input' => 3.00, 'output' => 12.00 ),
            'o1-preview'     => array( 'input' => 15.00, 'output' => 60.00 ),
            'o1'             => array( 'input' => 15.00, 'output' => 60.00 ),
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
        return isset( $this->settings['trp_machine_translation_settings']['openai-model'] )
            ? $this->settings['trp_machine_translation_settings']['openai-model']
            : 'gpt-4o-mini';
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

        if ( 'openai' === $translation_engine && 'yes' === $this->settings['trp_machine_translation_settings']['machine-translation'] ) {
            if ( isset( $this->correct_api_key ) && $this->correct_api_key !== null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = __( 'Please enter your OpenAI API key.', 'translatepress-llm-engines' );
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
                return __( 'Invalid API key. Please check your OpenAI API key.', 'translatepress-llm-engines' );
            case 429:
                return __( 'Rate limit exceeded or insufficient quota. Please check your OpenAI usage limits.', 'translatepress-llm-engines' );
            case 500:
            case 503:
                return __( 'OpenAI service temporarily unavailable. Please try again later.', 'translatepress-llm-engines' );
            default:
                return sprintf(
                    __( 'OpenAI API error (code %d): %s', 'translatepress-llm-engines' ),
                    $code,
                    $api_error
                );
        }
    }
}
