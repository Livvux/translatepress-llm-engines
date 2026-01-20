<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_DeepSeek_Machine_Translator extends TRP_Machine_Translator {

    private $api_url = 'https://api.deepseek.com/chat/completions';

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
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
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
        $chunk_size = apply_filters( 'trp_deepseek_chunk_size', 25 );
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
        return isset( $this->settings['trp_machine_translation_settings']['deepseek-api-key'] )
            ? $this->settings['trp_machine_translation_settings']['deepseek-api-key']
            : false;
    }

    private function get_model() {
        return isset( $this->settings['trp_machine_translation_settings']['deepseek-model'] )
            ? $this->settings['trp_machine_translation_settings']['deepseek-model']
            : 'deepseek-chat';
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

        if ( 'deepseek' === $translation_engine && 'yes' === $this->settings['trp_machine_translation_settings']['machine-translation'] ) {
            if ( isset( $this->correct_api_key ) && $this->correct_api_key !== null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = __( 'Please enter your DeepSeek API key.', 'translatepress-llm-engines' );
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
