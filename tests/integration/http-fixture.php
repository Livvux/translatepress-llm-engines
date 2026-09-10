<?php
/** Test-only HTTP boundary. Install only in the disposable CI WordPress site. */
if ( ! defined( 'TRP_LLM_INTEGRATION_TESTS' ) || ! TRP_LLM_INTEGRATION_TESTS || ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    global $wpdb;
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! in_array( $host, array( 'api.openai.com', 'api.anthropic.com', 'api.deepseek.com', 'openrouter.ai' ), true ) ) {
        return new WP_Error( 'integration_network_blocked', 'External HTTP blocked by integration fixture.' );
    }
    $response = static function ( $body, $code = 200, $headers = array() ) {
        return array( 'response' => array( 'code' => $code, 'message' => 'Fixture' ), 'headers' => $headers, 'body' => wp_json_encode( $body ), 'cookies' => array() );
    };
    if ( ( $args['method'] ?? 'GET' ) === 'GET' ) {
        return $response( array( 'data' => array( array( 'id' => 'gpt-4o-mini' ) ) ) );
    }
    $GLOBALS['fixture_calls'] = ( $GLOBALS['fixture_calls'] ?? 0 ) + 1;
    $body = json_decode( $args['body'], true );
    $GLOBALS['fixture_bodies'][] = $body;
    $mode = $GLOBALS['fixture_mode'] ?? 'success';
    if ( 'race' === $mode ) {
        $wpdb->query( "UPDATE {$wpdb->prefix}llm_fixture_counter SET calls=calls+1 WHERE id=1" );
        usleep( 300000 );
    }
    if ( 'timeout' === $mode ) { return new WP_Error( 'connect_error', 'Fixture connection error.' ); }
    if ( '429' === $mode || '503' === $mode ) { return $response( array( 'error' => array( 'message' => 'Fixture retry later.' ) ), (int) $mode, array( 'retry-after' => '600' ) ); }
    $messages = $body['messages'];
    $prompt = end( $messages )['content'];
    $strings = json_decode( substr( $prompt, strpos( $prompt, "\n" ) + 1 ), true );
    if ( ! is_array( $strings ) ) { throw new RuntimeException( 'Fixture received unexpected request shape.' ); }
    $translated = array_map( static function ( $source ) use ( $mode ) {
        return 'partial' === $mode && 'Broken content' === $source ? '' : 'DE: ' . $source;
    }, $strings );
    $content = 'malformed' === $mode ? 'not valid JSON' : wp_json_encode( $translated );
    if ( 'api.anthropic.com' === $host ) {
        $at = (int) floor( strlen( $content ) / 2 );
        return $response( array( 'stop_reason' => 'end_turn', 'content' => array(
            array( 'type' => 'thinking', 'thinking' => 'must never become a translation' ),
            array( 'type' => 'text', 'text' => substr( $content, 0, $at ) ),
            array( 'type' => 'text', 'text' => substr( $content, $at ) ),
        ) ) );
    }
    return $response( array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => $content ) ) ) ) );
}, PHP_INT_MAX, 3 );
