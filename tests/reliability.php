<?php
/** Real policy classes, with WordPress/HTTP I/O doubles; SQL is covered in integration/. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
error_reporting( E_ALL );
set_error_handler( static function ( $n, $s, $f, $l ) { throw new ErrorException( $s, 0, $n, $f, $l ); } );
$GLOBALS['filters'] = $GLOBALS['options'] = $GLOBALS['transients'] = array();
function apply_filters( $name, $value, ...$args ) {
    foreach ( $GLOBALS['filters'][ $name ] ?? array() as $fn ) { $value = $fn( $value, ...$args ); }
    return $value;
}
function add_filter( $name, $fn ) { $GLOBALS['filters'][ $name ][] = $fn; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; }
function get_transient( $name ) { return $GLOBALS['transients'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl ) { $GLOBALS['transients'][ $name ] = $value; }
function delete_transient( $name ) { unset( $GLOBALS['transients'][ $name ] ); }
function wp_salt( $scheme ) { return 'unit-test-not-a-production-salt'; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function __( $s, $domain = '' ) { return $s; }
function home_url() { return 'https://example.test'; }
function get_bloginfo( $s ) { return 'Test'; }
function do_action( ...$args ) {}
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? ( $r['headers'][ $h ] ?? '' ) : ''; }
function wp_remote_get( $url, $args ) { return $GLOBALS['http_response']; }
function wp_remote_post( $url, $args ) { $GLOBALS['posts'][] = $args; return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
class TRP_Machine_Translator { protected $settings; public function __construct( $s ) { $this->settings = $s; } }
foreach ( array( 'model-catalog', 'request-shape', 'request-retry', 'engine-cooldown', 'breadcrumb', 'translation-state', 'openai-machine-translator', 'anthropic-machine-translator', 'deepseek-machine-translator', 'openrouter-machine-translator' ) as $name ) { require ABSPATH . 'includes/class-' . $name . '.php'; }
$checks = $failures = 0;
function check( $ok, $name ) {
    global $checks, $failures; $checks++;
    if ( ! $ok ) { $failures++; fwrite( STDERR, "FAIL: $name\n" ); }
}
function context( $model = 'gpt-4o-mini', $engine = 'openai' ) { return TRP_LLM_Request_Shape::context( $engine, $model, 'English', 'German', 'en_US', 'de_DE', array( 'Hello %s' ) ); }
foreach ( TRP_LLM_Model_Catalog::openai_registry() as $id => $cap ) {
    $body = TRP_LLM_Request_Shape::body( context( $id ) );
    check( is_array( $body ), "$id builds" );
    check( isset( $body[ $cap['token_parameter'] ] ), "$id token field" );
    check( isset( $body['temperature'] ) === $cap['temperature'], "$id sampling support" );
    check( ! is_wp_error( TRP_LLM_Model_Catalog::validate( $body ) ), "$id validates" );
}
foreach ( array( 'gpt-4.1-2099-01-01', 'gpt-4o-realtime-preview', 'gpt-4o-audio-preview', 'gpt-image-1', 'gpt-5-pro', 'o1', 'ft:gpt-4o:unknown', '' ) as $id ) {
    check( is_wp_error( TRP_LLM_Request_Shape::body( context( $id ) ) ), "reject unsupported $id" );
}
check( TRP_LLM_Model_Catalog::parameters( 'gpt-4', 999999 )['max_tokens'] === 8192, 'model ceiling' );
check( TRP_LLM_Model_Catalog::parameters( 'gpt-5', 0 )['max_completion_tokens'] === 1, 'positive ceiling' );
check( str_contains( TRP_LLM_Model_Catalog::price_label( 'openai', 'gpt-4.1' ), '$2.00/' ), '4.1 not generic 4 price' );
check( str_contains( TRP_LLM_Model_Catalog::price_label( 'openai', 'gpt-4.1-mini-2025-04-14' ), '$0.40/' ), 'exact snapshot price' );
check( TRP_LLM_Model_Catalog::price_label( 'openai', 'gpt-4.1-mini-future' ) === 'Price unknown', 'unknown suffix price' );
check( TRP_LLM_Model_Catalog::price_label( 'deepseek', 'deepseek-v4-flash' ) === 'Price unknown', 'unverified DeepSeek price' );
foreach ( array( null, -1, INF, NAN, 'bad', array(), true ) as $value ) { check( ! TRP_LLM_Model_Catalog::valid_price( $value ), 'invalid price rejected' ); }
check( TRP_LLM_Model_Catalog::format_price( '0', 0 ) === 'Free', 'explicit zero free' );
check( TRP_LLM_Model_Catalog::format_price( null, 0 ) === 'Price unknown', 'absent not free' );
add_filter( 'trp_llm_request_body', static function ( $b ) { $b['temperature'] = 0.1; return $b; } );
check( is_wp_error( TRP_LLM_Request_Shape::body( context( 'gpt-5' ) ) ), 'incompatible filtered body fails locally' );
$GLOBALS['filters'] = array();
$GLOBALS['http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'data' => array_map( static function ( $id ) { return array( 'id' => $id ); }, array( 'gpt-4.1', 'gpt-4o-mini', 'gpt-5', 'gpt-4o-audio-preview', 'gpt-5-pro', 'gpt-4.1-2099-01-01', 'gpt-4.1' ) ) ) ) );
$models = TRP_OpenAI_Machine_Translator::get_available_models( 'dummy', true );
check( array_keys( $models ) === array( 'gpt-4o-mini', 'gpt-4.1', 'gpt-5' ), 'discovery exact positive registry and dedup' );
$GLOBALS['posts'] = array();
$engine = new TRP_OpenAI_Machine_Translator( array( 'trp_machine_translation_settings' => array( 'openai-model' => 'gpt-5-pro', 'openai-api-key' => 'dummy' ) ) );
check( is_wp_error( $engine->send_request( 'English', 'German', array( 'Hello' ) ) ) && count( $GLOBALS['posts'] ) === 0, 'unknown saved model never sent or silently substituted' );
$settings = array( 'openai-api-key' => 'dummy' );
$ctx = context();
$base = TRP_LLM_Translation_State::fingerprint( 'Hello', $ctx, $settings );
check( strlen( $base ) === 64, 'opaque fingerprint' );
$batch = $ctx; $batch['strings'] = array( 'Other', 'Hello' ); $batch['attempt'] = 1;
check( $base === TRP_LLM_Translation_State::fingerprint( 'Hello', $batch, $settings ), 'identity independent of batch and escalation' );
foreach ( array( 'model' => 'gpt-4.1', 'source_language_code' => 'en_GB', 'target_language_code' => 'de_AT' ) as $key => $value ) {
    $changed = $ctx; $changed[ $key ] = $value;
    check( $base !== TRP_LLM_Translation_State::fingerprint( 'Hello', $changed, $settings ), "identity includes $key" );
}
check( $base !== TRP_LLM_Translation_State::fingerprint( 'Hello!', $ctx, $settings ), 'identity includes source' );
check( $base !== TRP_LLM_Translation_State::fingerprint( 'Hello', $ctx, array( 'openai-api-key' => 'rotated' ) ), 'identity includes credential scope' );
foreach ( array( 'trp_llm_configuration_version', 'trp_llm_system_prompt', 'trp_llm_user_prompt' ) as $filter ) {
    add_filter( $filter, static function ( $v ) { return $v . ' changed'; } );
    check( $base !== TRP_LLM_Translation_State::fingerprint( 'Hello', $ctx, $settings ), "identity includes $filter" );
    $GLOBALS['filters'] = array();
}
for ( $i = 1; $i <= 14; $i++ ) {
    $delay = TRP_LLM_Translation_State::backoff_seconds( $i );
    check( $delay >= min( 86400, 60 * 2 ** ( $i - 1 ) ) && $delay <= min( 86400, 66 * 2 ** ( $i - 1 ) ), "bounded exponential backoff $i" );
}
$text = array( 'content' => array( array( 'type' => 'thinking', 'thinking' => 'private' ), array( 'type' => 'text', 'text' => '["Hal' ), array( 'type' => 'redacted_thinking', 'data' => 'private' ), array( 'type' => 'text', 'text' => 'lo"]' ) ) );
check( TRP_LLM_Request_Shape::message_content( $text ) === '["Hallo"]', 'typed Anthropic text concatenation, no thoughts' );
foreach ( array( array( 'type' => 'tool_use', 'text' => '["unsafe"]' ), array( 'text' => '["untyped"]' ), array( 'type' => 'text', 'text' => array() ) ) as $block ) {
    check( null === TRP_LLM_Request_Shape::message_content( array( 'content' => array( $block ) ) ), 'unsupported Anthropic block rejected' );
}
check( TRP_LLM_Request_Shape::was_truncated( array( 'stop_reason' => 'model_context_window_exceeded' ) ), 'Anthropic context limit recognized' );
foreach ( array( '600', gmdate( 'D, d M Y H:i:s \G\M\T', time() + 600 ) ) as $header ) {
    $r = array( 'response' => array( 'code' => 503 ), 'headers' => array( 'retry-after' => $header ) ); $calls = 0;
    $out = TRP_LLM_Request_Retry::send( static function () use ( &$calls, $r ) { $calls++; return $r; } );
    check( $calls === 1 && $out === $r, 'long Retry-After no premature inline retry' );
    check( TRP_LLM_Engine_Cooldown::seconds_for( 'http-5xx', $r, 'retryable', 'openai' ) >= 599, 'long 503 cooldown' );
}
add_filter( 'trp_llm_rate_limit_cooldown', static function () { return 1; } );
check( TRP_LLM_Engine_Cooldown::seconds_for( 'http-429', array( 'headers' => array( 'retry-after' => '7200' ) ), 'permanent' ) === 7200, 'long provider wait not reduced by filter or old hour cap' );
$GLOBALS['filters'] = array();
check( TRP_LLM_Request_Retry::retry_after_seconds( array( 'headers' => array( 'retry-after' => 'nonsense' ) ), 2 ) === 1, 'invalid header default' );
$calls = 0;
TRP_LLM_Request_Retry::send( static function () use ( &$calls ) { $calls++; return array( 'response' => array( 'code' => $calls === 1 ? 503 : 200 ), 'headers' => array( 'retry-after' => '0' ) ); } );
check( $calls === 2, 'immediate retry still bounded and functional' );
$GLOBALS['options']['trp_machine_translation_settings'] = array( 'openai-api-key' => 'secret-plain-key' );
check( TRP_LLM_Breadcrumb::excerpt( 'email@example.test secret-plain-key' ) === '[redacted]', 'content omitted by default' );
add_filter( 'trp_llm_diagnostic_content', static function () { return true; } );
foreach ( array( 'email@example.test', 'secret-plain-key', 'Bearer token.value', 'sk-project-secret123', 'https://example.test?api_key=password' ) as $secret ) {
    check( TRP_LLM_Breadcrumb::excerpt( $secret ) !== $secret, 'opted-in secret redacted' );
}
TRP_LLM_Breadcrumb::append( 'trp_llm_http_failures', array( array( 'time' => time() - 8 * DAY_IN_SECONDS, 'detail' => 'old' ), array( 'time' => time(), 'detail' => 'email@example.test', 'unexpected' => 'must not persist' ) ), 50 );
$log = get_option( 'trp_llm_http_failures' );
check( count( $log ) === 1 && ! isset( $log[0]['unexpected'] ) && $log[0]['detail'] === '[redacted]', 'retention and field allowlist' );
$GLOBALS['filters'] = array();
TRP_LLM_Breadcrumb::prune();
check( get_option( 'trp_llm_http_failures' )[0]['detail'] === '[redacted]', 'old snippets removed when opt-in disabled' );
add_filter( 'trp_llm_diagnostics_enabled', static function () { return false; } );
TRP_LLM_Breadcrumb::prune();
check( get_option( 'trp_llm_http_failures' ) === array(), 'diagnostics opt-out purges ring' );
echo "$checks reliability checks, $failures failures (PHP " . PHP_VERSION . ")\n";
exit( $failures ? 1 : 0 );
