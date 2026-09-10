<?php
/** Explicit request capabilities and separately verified list prices. See docs/RELIABILITY.md. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Model_Catalog {
    const VERSION = '2026-09-10.1';

    /** A discovery endpoint is not a guarantee that a model accepts this API. */
    public static function openai_registry() {
        $legacy = array( 'token_parameter' => 'max_tokens', 'temperature' => true, 'max_output_tokens' => 16384 );
        $modern = array( 'token_parameter' => 'max_completion_tokens', 'temperature' => false, 'max_output_tokens' => 128000 );
        $registry = array(
            'gpt-4' => array_replace( $legacy, array( 'max_output_tokens' => 8192 ) ),
            'gpt-4o-mini' => $legacy,
            'gpt-4o-mini-2024-07-18' => $legacy,
            'gpt-4o' => $legacy,
            'gpt-4o-2024-08-06' => $legacy,
            'gpt-4o-2024-11-20' => $legacy,
            'gpt-4o-2024-05-13' => array_replace( $legacy, array( 'max_output_tokens' => 4096 ) ),
        );
        foreach ( array( 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano' ) as $id ) {
            $registry[ $id ] = array_replace( $legacy, array( 'max_output_tokens' => 32768 ) );
            $registry[ $id . '-2025-04-14' ] = $registry[ $id ];
        }
        foreach ( array( 'gpt-5', 'gpt-5-mini', 'gpt-5-nano' ) as $id ) {
            $registry[ $id ] = $modern;
            $registry[ $id . '-2025-08-07' ] = $modern;
        }
        // No prefix matching: realtime/audio/image/pro/fine-tuned models need
        // their own verified entry, not a guess based on the first characters.
        return apply_filters( 'trp_llm_openai_capabilities', $registry );
    }

    public static function capability( $model ) {
        $registry = self::openai_registry();
        $entry = is_array( $registry ) ? ( $registry[ $model ] ?? null ) : null;
        if ( ! is_array( $entry ) || ! isset( $entry['token_parameter'], $entry['temperature'], $entry['max_output_tokens'] )
            || ! in_array( $entry['token_parameter'], array( 'max_tokens', 'max_completion_tokens' ), true )
            || ! is_bool( $entry['temperature'] ) || ! is_int( $entry['max_output_tokens'] ) || $entry['max_output_tokens'] < 1 ) {
            return null;
        }
        return $entry;
    }

    public static function parameters( $model, $tokens ) {
        $capability = self::capability( $model );
        if ( null === $capability ) {
            return new WP_Error( 'trp_llm_unsupported_model', 'Selected OpenAI model has no verified Chat Completions configuration. No request was sent.' );
        }
        $parameters = array( $capability['token_parameter'] => max( 1, min( $capability['max_output_tokens'], (int) $tokens ) ) );
        if ( $capability['temperature'] ) {
            $parameters['temperature'] = 0.1;
        }
        return $parameters;
    }

    /** Validate AFTER the public body filter as well; fail locally instead of billing a fallback. */
    public static function validate( array $body ) {
        $model = $body['model'] ?? '';
        $capability = is_string( $model ) ? self::capability( $model ) : null;
        if ( null === $capability ) {
            return new WP_Error( 'trp_llm_unsupported_model', 'Selected OpenAI model has no verified Chat Completions configuration. No request was sent.' );
        }
        $parameter = $capability['token_parameter'];
        $other = 'max_tokens' === $parameter ? 'max_completion_tokens' : 'max_tokens';
        if ( isset( $body[ $other ] ) || ( ! $capability['temperature'] && isset( $body['temperature'] ) )
            || ! isset( $body[ $parameter ] ) || ! is_int( $body[ $parameter ] )
            || $body[ $parameter ] < 1 || $body[ $parameter ] > $capability['max_output_tokens'] ) {
            return new WP_Error( 'trp_llm_incompatible_parameters', 'Filtered request does not match the selected model capabilities. No request was sent.' );
        }
        return $body;
    }

    /** USD per million standard text tokens; not an invoice or usage estimate. */
    public static function prices() {
        $prices = array( 'openai' => array(
            'gpt-4o-mini' => array( 0.15, 0.60 ),
            'gpt-4o-mini-2024-07-18' => array( 0.15, 0.60 ),
            'gpt-4o' => array( 2.50, 10.00 ),
            'gpt-4o-2024-08-06' => array( 2.50, 10.00 ),
            'gpt-4o-2024-11-20' => array( 2.50, 10.00 ),
            'gpt-4.1' => array( 2.00, 8.00 ),
            'gpt-4.1-2025-04-14' => array( 2.00, 8.00 ),
            'gpt-4.1-mini' => array( 0.40, 1.60 ),
            'gpt-4.1-mini-2025-04-14' => array( 0.40, 1.60 ),
            'gpt-4.1-nano' => array( 0.10, 0.40 ),
            'gpt-4.1-nano-2025-04-14' => array( 0.10, 0.40 ),
            'gpt-5' => array( 1.25, 10.00 ),
            'gpt-5-2025-08-07' => array( 1.25, 10.00 ),
            'gpt-5-mini' => array( 0.25, 2.00 ),
            'gpt-5-mini-2025-08-07' => array( 0.25, 2.00 ),
            'gpt-5-nano' => array( 0.05, 0.40 ),
            'gpt-5-nano-2025-08-07' => array( 0.05, 0.40 ),
        ), 'anthropic' => array(
            'claude-haiku-4-5' => array( 1.00, 5.00 ),
            'claude-haiku-4-5-20251001' => array( 1.00, 5.00 ),
            'claude-sonnet-4-6' => array( 3.00, 15.00 ),
            'claude-sonnet-5' => array( 2.00, 10.00 ),
            'claude-opus-5' => array( 5.00, 25.00 ),
        ) );
        // Other providers: unknown until an exact price is verified or supplied
        // by a site filter. In particular, a new DeepSeek model must not inherit
        // the old deepseek-chat price. OpenRouter uses its live price metadata.
        return apply_filters( 'trp_llm_model_prices', $prices );
    }

    public static function price_label( $engine, $model ) {
        $prices = self::prices();
        $price = $prices[ $engine ][ $model ] ?? null;
        return is_array( $price ) && count( $price ) === 2
            ? self::format_price( $price[0] ?? null, $price[1] ?? null )
            : __( 'Price unknown', 'translatepress-llm-engines' );
    }

    public static function valid_price( $price ) {
        return is_numeric( $price ) && is_finite( (float) $price ) && (float) $price >= 0;
    }

    public static function format_price( $input, $output ) {
        if ( ! self::valid_price( $input ) || ! self::valid_price( $output ) ) {
            return __( 'Price unknown', 'translatepress-llm-engines' );
        }
        if ( 0.0 === (float) $input && 0.0 === (float) $output ) {
            return __( 'Free', 'translatepress-llm-engines' );
        }
        return sprintf( '$%.2f/1M in, $%.2f/1M out', $input, $output );
    }
}
