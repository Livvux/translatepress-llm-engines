<?php
/** Launched as separate OS processes, each with its own WordPress/SQL connection. */
if ( ! defined( 'TRP_LLM_INTEGRATION_TESTS' ) || ! TRP_LLM_INTEGRATION_TESTS || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
$mode = getenv( 'LLM_TEST_MODE' );
$gate = getenv( 'LLM_TEST_GATE' );
$deadline = microtime( true ) + 20;
while ( ! file_exists( $gate ) && microtime( true ) < $deadline ) { usleep( 10000 ); clearstatcache( true, $gate ); }
if ( ! file_exists( $gate ) ) { WP_CLI::error( 'Barrier timed out.' ); }
if ( 'cost' === $mode ) {
    for ( $i = 0; $i < 50; $i++ ) {
        if ( ! TRP_LLM_Cost_Ledger::record( 'openrouter', array( 'usage' => array( 'cost' => '0.01' ) ) ) ) { WP_CLI::error( 'Cost write failed.' ); }
    }
} elseif ( 'race' === $mode ) {
    $GLOBALS['fixture_mode'] = 'race';
    $engine = TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator' );
    // Direct engine entry intentionally avoids TP's own separate dictionary locks.
    $result = $engine->translate_array( array( 123 => 'Concurrent translation' ), 'de_DE' );
    if ( $result !== array() && $result !== array( 123 => 'DE: Concurrent translation' ) ) { WP_CLI::error( 'Unexpected concurrent result.' ); }
} else { WP_CLI::error( 'Unknown worker mode.' ); }
WP_CLI::success( 'Worker completed: ' . $mode );
