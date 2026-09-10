<?php
/** Real WordPress + TranslatePress + MariaDB. Only provider HTTP is a fixture. */
if ( ! defined( 'TRP_LLM_INTEGRATION_TESTS' ) || ! TRP_LLM_INTEGRATION_TESTS || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
global $wpdb;
$checks = 0;
function expect( $condition, $name ) {
    global $checks; $checks++;
    if ( ! $condition ) { WP_CLI::error( 'FAIL: ' . $name ); }
    WP_CLI::log( 'PASS: ' . $name );
}
function reset_fixture( $mode = 'success' ) {
    global $wpdb;
    $GLOBALS['fixture_mode'] = $mode; $GLOBALS['fixture_calls'] = 0; $GLOBALS['fixture_bodies'] = array();
    unset( $GLOBALS['trp_machine_translation_deadline'] );
    foreach ( array( 'openai', 'anthropic', 'openrouter', 'deepseek' ) as $engine ) { TRP_LLM_Engine_Cooldown::clear( $engine ); }
    $wpdb->query( 'DELETE FROM ' . TRP_LLM_Storage::table( 'state' ) );
    $wpdb->query( 'DELETE FROM ' . TRP_LLM_Storage::table( 'costs' ) );
}
function parallel_workers( $mode ) {
    $gate = sys_get_temp_dir() . '/llm-gate-' . bin2hex( random_bytes( 8 ) );
    $workers = array();
    for ( $i = 0; $i < 8; $i++ ) {
        $log = $gate . '-' . $i . '.log';
        $command = 'LLM_TEST_MODE=' . escapeshellarg( $mode ) . ' LLM_TEST_GATE=' . escapeshellarg( $gate ) . ' wp eval-file ' . escapeshellarg( __DIR__ . '/worker.php' ) . ' --path=' . escapeshellarg( ABSPATH );
        $process = proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $log, 'w' ), 2 => array( 'file', $log, 'a' ) ), $pipes );
        if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not launch worker.' ); }
        $workers[] = array( $process, $log );
    }
    // All workers can reach the provider concurrently; none uses the parent's SQL connection.
    touch( $gate );
    foreach ( $workers as list( $process, $log ) ) {
        $status = proc_close( $process );
        if ( 0 !== $status ) { WP_CLI::log( file_get_contents( $log ) ); }
        expect( 0 === $status, $mode . ' worker exits successfully' );
        unlink( $log );
    }
    unlink( $gate );
}
$trp = TRP_Translate_Press::get_trp_instance();
$engine = $trp->get_component( 'machine_translator' );
$query = $trp->get_component( 'query' );
$renderer = $trp->get_component( 'translation_render' );
$settings = $trp->get_component( 'settings' )->get_settings();
expect( $engine instanceof TRP_OpenAI_Machine_Translator, 'plugin boot registers real OpenAI engine' );
expect( TRP_LLM_Storage::ensure(), 'upgrade installs state and cost tables' );
$state = TRP_LLM_Storage::table( 'state' ); $costs = TRP_LLM_Storage::table( 'costs' );
$query->check_table( 'en_US', 'de_DE' );
$dictionary = $query->get_table_name( 'de_DE' );
reset_fixture( 'partial' );
$source = array( 'Hello %s', 'Broken content', '<b>Welcome</b>', '日本語テキスト' );
$rendered = $renderer->process_strings( $source, 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 1, 'real renderer sends one paid fixture batch' );
$rows = $query->get_existing_translations( $source, 'de_DE' );
expect( isset( $rows['Hello %s'] ) && $rows['Hello %s']->translated === 'DE: Hello %s' && (int) $rows['Hello %s']->status === $query->get_constant_machine_translated(), 'real TP persistence restores placeholders and stores machine status' );
expect( isset( $rows['Broken content'] ) && (int) $rows['Broken content']->status === $query->get_constant_not_translated() && empty( $rows['Broken content']->translated ), 'rejected sibling remains pending in real dictionary' );
expect( $rows['<b>Welcome</b>']->translated === 'DE: <b>Welcome</b>', 'HTML survives dictionary persistence' );
expect( $rows['日本語テキスト']->translated === 'DE: 日本語テキスト', 'Unicode survives dictionary persistence' );
$renderer->process_strings( $source, 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 1, 'later render does not repay rejected content' );
expect( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state} WHERE failures=1 AND retry_at>UNIX_TIMESTAMP()" ) === 1, 'only rejected string receives backoff' );
$renderer->process_strings( array( 'Independent new text' ), 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 2, 'unrelated content proceeds during string backoff' );
// A reviewed translation must not be overwritten by machine results.
$wpdb->update( $dictionary, array( 'translated' => 'Human approved', 'status' => $query->get_constant_human_reviewed() ), array( 'id' => $rows['Hello %s']->id ) );
$renderer->process_strings( array( 'Hello %s' ), 'de_DE' );
$reviewed = $query->get_existing_translations( array( 'Hello %s' ), 'de_DE' );
expect( $reviewed['Hello %s']->translated === 'Human approved' && $GLOBALS['fixture_calls'] === 2, 'existing human-reviewed translation unchanged' );
$wpdb->query( "UPDATE {$state} SET retry_at=0 WHERE failures>0" );
$GLOBALS['fixture_mode'] = 'success';
$renderer->process_strings( array( 'Broken content' ), 'de_DE' );
$rows = $query->get_existing_translations( array( 'Broken content' ), 'de_DE' );
expect( $rows['Broken content']->translated === 'DE: Broken content', 'expired backoff retries and persists successfully' );
expect( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state} WHERE failures>0" ), 'successful retry resets failures' );
// Explicitly prevent one save to exercise the engine-return / dictionary-write gap.
reset_fixture();
add_filter( 'trp_allow_string_saving', '__return_false', PHP_INT_MAX );
$renderer->process_strings( array( 'Delayed database save' ), 'de_DE' );
remove_filter( 'trp_allow_string_saving', '__return_false', PHP_INT_MAX );
$renderer->process_strings( array( 'Delayed database save' ), 'de_DE' );
$rows = $query->get_existing_translations( array( 'Delayed database save' ), 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 1 && $rows['Delayed database save']->translated === 'DE: Delayed database save', 'handoff avoids repaying before a delayed real dictionary save' );
// Direct entry covers model/locales/config fingerprints without TP's independent locks.
reset_fixture( 'malformed' );
expect( array() === $engine->translate_array( array( 9 => 'Malformed answer source' ), 'de_DE' ), 'malformed HTTP-success output omitted' );
$engine->translate_array( array( 9 => 'Malformed answer source' ), 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 1, 'malformed answer gets persistent per-string backoff' );
add_filter( 'trp_llm_configuration_version', static function () { return 'changed-glossary'; } );
$engine->translate_array( array( 9 => 'Malformed answer source' ), 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 2, 'configuration change creates independent retry identity' );
remove_all_filters( 'trp_llm_configuration_version' );
reset_fixture();
$result = $engine->translate_array( array( 4 => 'Same source', 19 => 'Same source' ), 'de_DE' );
expect( $result === array( 4 => 'DE: Same source', 19 => 'DE: Same source' ) && $GLOBALS['fixture_calls'] === 1, 'duplicate source keys share one result' );
$prompt = $GLOBALS['fixture_bodies'][0]['messages'][1]['content'];
expect( count( json_decode( substr( $prompt, strpos( $prompt, "\n" ) + 1 ), true ) ) === 1, 'duplicates not sent twice inside batch' );
// Leases are owner-fenced even when a stale PHP worker resumes after expiration.
reset_fixture();
$id = hash( 'sha256', 'lease-test' ); $a = TRP_LLM_Translation_State::claim( $id );
expect( 'acquired' === $a['status'], 'first lease owner wins' );
expect( 'locked' === TRP_LLM_Translation_State::claim( $id )['status'], 'second owner cannot claim live lease' );
expect( TRP_LLM_Translation_State::renew( $id, $a['owner'] ), 'owner can renew lease' );
$wpdb->query( "UPDATE {$state} SET lease_until=UNIX_TIMESTAMP()-1 WHERE fingerprint='{$id}'" );
$b = TRP_LLM_Translation_State::claim( $id );
expect( 'acquired' === $b['status'] && $a['owner'] !== $b['owner'], 'expired lease can be reclaimed' );
TRP_LLM_Translation_State::release( $id, $a['owner'] );
TRP_LLM_Translation_State::finish( $id, $a, 'stale result', false );
expect( 'locked' === TRP_LLM_Translation_State::claim( $id )['status'], 'stale owner cannot release or finish successor lease' );
expect( ! TRP_LLM_Translation_State::renew( $id, $a['owner'] ), 'stale owner cannot renew' );
TRP_LLM_Translation_State::finish( $id, $b, 'safe result', false );
expect( TRP_LLM_Translation_State::claim( $id )['result'] === 'safe result', 'successful result handoff visible across SQL reads' );
// An exception while claiming a later string must release earlier claims.
reset_fixture();
$throw_filter = static function ( $v, $ctx ) { if ( in_array( 'Throw while fingerprinting', $ctx['strings'], true ) ) { throw new RuntimeException( 'fixture' ); } return $v; };
add_filter( 'trp_llm_configuration_version', $throw_filter, 10, 2 );
try { $engine->translate_array( array( 'Acquired before exception', 'Throw while fingerprinting' ), 'de_DE' ); } catch ( RuntimeException $e ) { expect( 'fixture' === $e->getMessage(), 'expected filter exception observed' ); }
remove_filter( 'trp_llm_configuration_version', $throw_filter );
expect( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state} WHERE owner<>''" ), 'finally releases earlier claims after fingerprint exception' );
foreach ( array( '429', '503' ) as $status ) {
    reset_fixture( $status ); $engine->translate_array( array( 'Retry after response ' . $status ), 'de_DE' );
    expect( $GLOBALS['fixture_calls'] === 1, $status . ' long Retry-After not retried inline' );
    expect( get_option( '_transient_timeout_trp_llm_cooldown_openai' ) >= time() + 598, $status . ' provider cooldown preserves requested wait' );
    expect( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state} WHERE failures>0" ), $status . ' does not poison content backoff' );
}
reset_fixture();
$GLOBALS['trp_machine_translation_deadline'] = microtime( true ) - 1;
$engine->translate_array( array( 'No budget source' ), 'de_DE' );
expect( $GLOBALS['fixture_calls'] === 0 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state} WHERE failures>0 OR owner<>''" ), 'budget refusal leaves no content failure or held lease' );
unset( $GLOBALS['trp_machine_translation_deadline'] );
// Verify actual Anthropic sender and runner, not only the content helper.
reset_fixture();
$anthropic_settings = $settings;
$anthropic_settings['trp_machine_translation_settings']['anthropic-api-key'] = 'integration-not-real';
$anthropic_settings['trp_machine_translation_settings']['anthropic-model'] = 'claude-haiku-4-5';
$anthropic = new TRP_Anthropic_Machine_Translator( $anthropic_settings );
expect( $anthropic->translate_array( array( 6 => 'Typed Claude response' ), 'de_DE' ) === array( 6 => 'DE: Typed Claude response' ), 'Anthropic typed blocks pass actual sender and runner' );
// The real vendor logger must accept redacted flat string arrays in both modes.
add_filter( 'trp_llm_diagnostic_content', '__return_true' );
$engine->translate_array( array( 'Contact private@example.test' ), 'de_DE' );
remove_filter( 'trp_llm_diagnostic_content', '__return_true' );
$logs = $wpdb->get_results( "SELECT strings,response FROM {$wpdb->prefix}trp_machine_translation_log", ARRAY_A );
expect( count( $logs ) > 0, 'real vendor log accepts metadata and opted-in samples' );
expect( ! str_contains( wp_json_encode( $logs ), 'private@example.test' ), 'opted-in email redacted before real vendor persistence' );
// Separate OS processes contend for the same plugin-owned lease.
reset_fixture();
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}llm_fixture_counter (id INT PRIMARY KEY,calls INT NOT NULL DEFAULT 0)" );
$wpdb->query( "REPLACE INTO {$wpdb->prefix}llm_fixture_counter (id,calls) VALUES(1,0)" );
parallel_workers( 'race' );
expect( 1 === (int) $wpdb->get_var( "SELECT calls FROM {$wpdb->prefix}llm_fixture_counter WHERE id=1" ), '8 concurrent WordPress workers cause exactly one mocked paid request' );
reset_fixture();
parallel_workers( 'cost' );
$row = $wpdb->get_row( "SELECT * FROM {$costs} WHERE engine='openrouter' AND coverage='reported'", ARRAY_A );
expect( $row['cost_usd'] === '4.000000000000' && (int) $row['responses'] === 400, '400 concurrent decimal increments have no lost updates' );
TRP_LLM_Cost_Ledger::record( 'openai', array() );
TRP_LLM_Cost_Ledger::record( 'openai', array( 'usage' => array( 'cost' => 0 ) ) );
$coverage = $wpdb->get_results( "SELECT coverage,responses FROM {$costs} WHERE engine='openai' ORDER BY coverage", ARRAY_A );
expect( count( $coverage ) === 2 && $coverage[0]['coverage'] === 'reported' && $coverage[1]['coverage'] === 'unknown', 'unknown amounts distinct from explicitly reported zero cost' );
remove_filter( 'pre_option_trp_llm_cost_daily', array( 'TRP_LLM_Cost_Ledger', 'legacy_totals' ) );
update_option( 'trp_llm_cost_daily', array( gmdate( 'Y-m-d' ) . '|openrouter' => 1.25, '2026-99-99|openai' => 2 ), false );
delete_option( 'trp_llm_cost_migrated' );
TRP_LLM_Cost_Ledger::migrate_legacy();
delete_option( 'trp_llm_cost_migrated' );
TRP_LLM_Cost_Ledger::migrate_legacy();
expect( (float) $wpdb->get_var( "SELECT SUM(cost_usd) FROM {$costs} WHERE coverage='legacy'" ) === 1.25, 'legacy migration idempotent and rejects invalid dates' );
expect( TRP_LLM_Cost_Ledger::legacy_totals( false )[ gmdate( 'Y-m-d' ) . '|openrouter' ] === 5.25, 'old option read view includes legacy and reported amounts' );
add_filter( 'pre_option_trp_llm_cost_daily', array( 'TRP_LLM_Cost_Ledger', 'legacy_totals' ) );
// Retention removes expired state without deleting a renewed live lease.
$live = TRP_LLM_Translation_State::claim( hash( 'sha256', 'live-cleanup' ) );
$wpdb->query( "UPDATE {$state} SET expires_at=0" );
$wpdb->query( "INSERT INTO {$costs} VALUES ('2000-01-01','openai','reported',1,1)" );
TRP_LLM_Storage::cleanup();
expect( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state}" ), 'cleanup preserves live lease' );
expect( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$costs} WHERE day='2000-01-01'" ), 'expired cost rows removed' );
// Storage unavailable => fail closed, never silently buy an unlocked translation.
$wpdb->query( "RENAME TABLE {$state} TO {$state}_offline" );
$wpdb->suppress_errors( true ); $before = $GLOBALS['fixture_calls'];
$result = $engine->translate_array( array( 'Unavailable state storage' ), 'de_DE' );
$wpdb->suppress_errors( false );
$wpdb->query( "RENAME TABLE {$state}_offline TO {$state}" );
expect( array() === $result && $GLOBALS['fixture_calls'] === $before, 'unavailable state storage sends no paid request' );
WP_CLI::success( "$checks integration assertions; WordPress " . get_bloginfo( 'version' ) . '; TranslatePress ' . TRP_PLUGIN_VERSION );
