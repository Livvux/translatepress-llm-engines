<?php
/**
 * Dependency-free unit-test harness. Never boot this through a web request.
 *
 * The three production classes under test are loaded by run.php. Collaborators
 * below are explicit test doubles for WordPress I/O, transport, budget, cooldown,
 * response metadata and the TranslatePress logger. These are not integration
 * tests of those collaborators or a running WordPress installation.
 */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) {
    throw new ErrorException( $message, 0, $severity, $file, $line );
} );

function wp_remote_retrieve_body( $response ) {
    return $response['body'] ?? '';
}

class TRP_LLM_Breadcrumb {
    public static $entries = array();

    public static function excerpt( $value, $limit = 500 ) {
        return is_string( $value ) ? substr( $value, 0, $limit ) : '';
    }

    public static function append( $option, $entries, $cap ) {
        self::$entries[ $option ] = array_slice(
            array_merge( self::$entries[ $option ] ?? array(), $entries ),
            -$cap
        );
    }
}

class TRP_LLM_Request_Retry {
    public static $worthwhile = true;

    public static function send_is_worthwhile() {
        return self::$worthwhile;
    }

    public static function send( $sender ) {
        return $sender();
    }

    public static function classify( $response ) {
        return 200 === ( $response['response']['code'] ?? 0 ) ? 'ok' : 'permanent';
    }
}

class TRP_LLM_Http_Failure_Log {
    public static function record( $engine, $model, $target, $response ) {
        $code = $response['response']['code'] ?? 0;
        return 200 === $code ? 'ok' : 'http-' . $code;
    }
}

class TRP_LLM_Engine_Cooldown {
    public static $reasons = array();
    public static $noted = array();

    public static function active( $engine ) {
        return self::$reasons[ $engine ] ?? '';
    }

    public static function start( $engine, $reason, $response, $classification ) {
        self::$reasons[ $engine ] = $reason;
    }

    public static function note_skip( $key ) {
        if ( isset( self::$noted[ $key ] ) ) {
            return false;
        }
        self::$noted[ $key ] = true;
        return true;
    }
}

class TRP_LLM_Request_Shape {
    public static $costs = array();

    public static function record_cost( $engine, $body ) {
        self::$costs[] = array( $engine, $body );
    }

    public static function message_content( $body ) {
        $content = $body['choices'][0]['message']['content'] ?? $body['content'][0]['text'] ?? null;
        return is_string( $content ) ? $content : null;
    }

    public static function finish_reason( $body ) {
        return $body['choices'][0]['finish_reason'] ?? $body['stop_reason'] ?? '';
    }

    public static function was_truncated( $body ) {
        return in_array( self::finish_reason( $body ), array( 'length', 'max_tokens' ), true );
    }
}

class TRP_LLM_Test_Logger {
    public $charged = array();
    public $logs = array();
    public $max_charged_requests = PHP_INT_MAX;

    public function quota_exceeded() {
        return count( $this->charged ) >= $this->max_charged_requests;
    }

    public function count_towards_quota( $chunk ) {
        $this->charged[] = $chunk;
    }

    public function log( $entry ) {
        $this->logs[] = $entry;
    }
}

function trp_test_same( $expected, $actual ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException( 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
    }
}

function trp_test_reset() {
    TRP_LLM_Breadcrumb::$entries = array();
    TRP_LLM_Request_Retry::$worthwhile = true;
    TRP_LLM_Engine_Cooldown::$reasons = array();
    TRP_LLM_Engine_Cooldown::$noted = array();
    TRP_LLM_Request_Shape::$costs = array();
}

function trp_test_response( $content, $reason = 'stop', $anthropic = false ) {
    $body = $anthropic
        ? array( 'content' => array( array( 'type' => 'text', 'text' => $content ) ), 'stop_reason' => $reason )
        : array( 'choices' => array( array( 'message' => array( 'content' => $content ), 'finish_reason' => $reason ) ) );

    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_THROW_ON_ERROR ) );
}

function trp_test_run_chunk( $chunk, $sender, $logger = null, $settings = array(), $engine = 'openai' ) {
    $context = array(
        'engine' => $engine,
        'model' => 'test-model',
        'source_language' => 'English',
        'target_language' => 'German',
        'source_language_code' => 'en_US',
        'target_language_code' => 'de_DE',
        'strings' => array_values( $chunk ),
        'attempt' => 0,
    );

    return TRP_LLM_Chunk_Runner::run( $chunk, $context, $logger ?? new TRP_LLM_Test_Logger(), $settings, $sender );
}
