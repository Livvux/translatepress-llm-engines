<?php
/** Run with `php tests/run.php`; no Composer, database, network or API keys. */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
require __DIR__ . '/bootstrap.php';

// An optional directory permits before/after checks against an unmodified copy.
$source_dir = $argv[1] ?? dirname( __DIR__ ) . '/includes';
foreach ( array( 'class-response-normalizer.php', 'class-placeholder-guard.php', 'class-chunk-runner.php' ) as $file ) {
    require $source_dir . '/' . $file;
}

$tests = array();
$parse_cases = array(
    'plain JSON list' => array( '["Hallo","Welt"]', array( 'Hallo', 'Welt' ) ),
    'translation wrapper' => array( '{"translations":["Hallo"]}', array( 'Hallo' ) ),
    'translated wrapper' => array( '{"translated":["Hallo"]}', array( 'Hallo' ) ),
    'items wrapper' => array( '{"items":["Hallo"]}', array( 'Hallo' ) ),
    'per-element objects' => array( '[{"translation":"Hallo"},{"translated_text":"Welt"}]', array( 'Hallo', 'Welt' ) ),
    'unknown elements retain their position' => array( '["Hallo",{"error":"no"},"Welt"]', array( 'Hallo', null, 'Welt' ) ),
    'numeric elements retain existing behavior' => array( '[12,1.5]', array( '12', '1.5' ) ),
    'booleans and null are not translations' => array( '[true,false,null]', array( null, null, null ) ),
    'single valued list element' => array( '[["Hallo"]]', array( 'Hallo' ) ),
    'unknown object is rejected' => array( '{"error":"no"}', array() ),
    'bare JSON string' => array( '"Hallo"', array( 'Hallo' ) ),
    'double encoded list' => array( json_encode( '["Hallo"]' ), array( 'Hallo' ) ),
    'code fence' => array( "```json\n[\"Hallo\"]\n```", array( 'Hallo' ) ),
    'paragraph wrapper' => array( '<p>["Hallo"]</p>', array( 'Hallo' ) ),
    'literal backticks are preserved' => array( '["Use ```code``` here"]', array( 'Use ```code``` here' ) ),
    'trailing comma is repaired' => array( '["Hallo",]', array( 'Hallo' ) ),
    'comma inside a string is preserved' => array( '["Optionen, ] und mehr",]', array( 'Optionen, ] und mehr' ) ),
    'surplus closing bracket is repaired' => array( '"Hallo"]', array( 'Hallo' ) ),
    'surplus closers with whitespace are repaired' => array( "\"Hallo\"] }\n", array( 'Hallo' ) ),
    'values without array delimiters are repaired' => array( '"Hallo","Welt"', array( 'Hallo', 'Welt' ) ),
    'truncated string is not completed' => array( '["Hal', array() ),
    'missing array closer is not invented' => array( '["Hallo"', array() ),
    'trailing value after surplus bracket is rejected' => array( '"Hallo"] "Welt"', array() ),
    'trailing prose after surplus bracket is rejected' => array( '["Hallo"]] explanation', array() ),
    'one based positions' => array( '{"1":"Hallo","2":"Welt"}', array( 'Hallo', 'Welt' ) ),
    'shuffled one based positions' => array( '{"2":"Welt","1":"Hallo"}', array( 'Hallo', 'Welt' ) ),
    'shuffled zero based positions' => array( '{"1":"Welt","0":"Hallo"}', array( 'Hallo', 'Welt' ) ),
    'gapped one based positions are rejected' => array( '{"1":"Hallo","3":"Welt"}', array() ),
    'gapped zero based positions are rejected' => array( '{"0":"Hallo","2":"Welt"}', array() ),
    'arbitrary offsets are rejected' => array( '{"5":"Hallo","6":"Welt"}', array() ),
    'negative positions are rejected' => array( '{"-1":"Hallo","0":"Welt"}', array() ),
    'leading zero positions are rejected' => array( '{"01":"Hallo","02":"Welt"}', array() ),
    'aliased numeric positions are rejected' => array( '{"1":"Hallo","01":"Welt"}', array() ),
    'noncanonical decimal positions are rejected' => array( '{"1.0":"Hallo"}', array() ),
    'empty object' => array( '{}', array() ),
    'empty array' => array( '[]', array() ),
    'non-string input' => array( null, array() ),
);
foreach ( $parse_cases as $name => $case ) {
    $tests[ 'parser: ' . $name ] = static function () use ( $case ) {
        trp_test_same( $case[1], TRP_LLM_Response_Normalizer::parse( $case[0] ) );
    };
}

$repeat_cases = array(
    'exact repeated string' => array( array( 'Hallo', 'Hallo' ), 1, array( 'Hallo' ) ),
    'exact repeated batch' => array( array( 'Hallo', 'Welt', 'Hallo', 'Welt' ), 2, array( 'Hallo', 'Welt' ) ),
    'exact Unicode repeats' => array( array( 'Grüße 世界', 'Grüße 世界' ), 1, array( 'Grüße 世界' ) ),
    'shorter first prefix is not a duplicate' => array( array( 'Delete account', 'Delete account now' ), 1, null ),
    'shorter second prefix is not a duplicate' => array( array( 'Delete account now', 'Delete account' ), 1, null ),
    'different case is not an exact repeat' => array( array( '{Name}', '{name}' ), 1, null ),
    'meaningful whitespace is not normalized' => array( array( '<pre>a  b</pre>', '<pre>a b</pre>' ), 1, null ),
    'incomplete repeated batch is rejected' => array( array( 'A', 'B', 'A' ), 2, null ),
    'empty repeats are rejected' => array( array( '', '' ), 1, null ),
    'non-string repeats are rejected' => array( array( null, null ), 1, null ),
    'zero expectation is rejected' => array( array( 'A' ), 0, null ),
);
foreach ( $repeat_cases as $name => $case ) {
    $tests[ 'repetition: ' . $name ] = static function () use ( $case ) {
        trp_test_same( $case[2], TRP_LLM_Response_Normalizer::trim_repetition( $case[0], $case[1] ) );
    };
}

$guard_cases = array(
    'plain translation' => array( 'Hello', 'Hallo', true ),
    'blank answer is rejected' => array( 'Hello', '', false ),
    'ASCII whitespace answer is rejected' => array( 'Hello', " \t\r\n", false ),
    'Unicode whitespace answer is rejected' => array( 'Hello', "\u{00A0}\u{2003}", false ),
    'empty source may remain empty' => array( '', '', true ),
    'blank layout source may remain blank' => array( "\u{00A0}", ' ', true ),
    'zero is not blank' => array( '0', '0', true ),
    'non-string answer is rejected' => array( 'Hello', array(), false ),
    'printf reorder' => array( '%1$s before %2$s', '%2$s nach %1$s', true ),
    'missing printf token' => array( 'Hi %s', 'Hallo', false ),
    'invented printf token' => array( 'Hello', 'Hallo %s', false ),
    'duplicate printf token' => array( 'Hi %s', 'Hallo %s %s', false ),
    'mustache token' => array( 'Hi {{name}}', 'Hallo {{name}}', true ),
    'renamed mustache token' => array( 'Hi {{name}}', 'Hallo {{Name}}', false ),
    'renamed brace token' => array( 'Hi {name}', 'Hallo {Name}', false ),
    'TranslatePress token' => array( 'Hi 1TP23T', 'Hallo 1TP23T', true ),
    'missing TranslatePress token' => array( 'Hi 1TP23T', 'Hallo', false ),
    'percent prose remains valid' => array( '100% ready', '100%-bereit', true ),
    'percent encoded text remains valid' => array( '%d9%87%d8%af', '%d9%87%d8%af', true ),
);
foreach ( $guard_cases as $name => $case ) {
    $tests[ 'guard: ' . $name ] = static function () use ( $case ) {
        trp_test_same( $case[2], TRP_LLM_Placeholder_Guard::is_safe( $case[0], $case[1] ) );
    };
}

$tests['guard: rejected siblings do not shift source keys'] = static function () {
    $result = TRP_LLM_Placeholder_Guard::filter_chunk(
        array( 17 => 'Hello', 29 => 'World', 'last' => 'Hi %s' ),
        array( '', 'Welt', 'Hallo %s' ),
        'openai'
    );
    trp_test_same( array( 29 => 'Welt', 'last' => 'Hallo %s' ), $result );
    trp_test_same( 1, count( TRP_LLM_Breadcrumb::$entries['trp_llm_placeholder_rejections'] ) );
};

foreach ( array( 'openai', 'openrouter', 'deepseek', 'anthropic' ) as $engine ) {
    $tests[ 'runner: successful ' . $engine . ' response preserves keys' ] = static function () use ( $engine ) {
        $logger = new TRP_LLM_Test_Logger();
        $result = trp_test_run_chunk( array( 7 => 'Hello', 21 => 'World' ), static function ( $part, $attempt ) use ( $engine ) {
            trp_test_same( 0, $attempt );
            trp_test_same( array( 7 => 'Hello', 21 => 'World' ), $part );
            return trp_test_response( '["Hallo","Welt"]', 'anthropic' === $engine ? 'end_turn' : 'stop', 'anthropic' === $engine );
        }, $logger, array(), $engine );
        trp_test_same( array( 7 => 'Hallo', 21 => 'Welt' ), $result );
        trp_test_same( 1, count( $logger->charged ) );
        trp_test_same( 1, count( TRP_LLM_Request_Shape::$costs ) );
        trp_test_same( array(), $logger->logs );
    };
}

foreach ( array( false, true ) as $anthropic ) {
    foreach ( array( '["Partial"]', null ) as $initial_content ) {
        $name = ( $anthropic ? 'Anthropic' : 'chat' ) . ( null === $initial_content ? ' absent content' : ' matching count' );
        $tests[ 'runner: truncation escalates before accepting ' . $name ] = static function () use ( $anthropic, $initial_content ) {
            $calls = 0;
            $logger = new TRP_LLM_Test_Logger();
            $result = trp_test_run_chunk( array( 17 => 'Original' ), static function ( $part, $attempt ) use ( &$calls, $anthropic, $initial_content ) {
                ++$calls;
                trp_test_same( 1 === $calls ? 0 : 1, $attempt );
                return 1 === $calls
                    ? trp_test_response( $initial_content, $anthropic ? 'max_tokens' : 'length', $anthropic )
                    : trp_test_response( '["Vollständig"]', $anthropic ? 'end_turn' : 'stop', $anthropic );
            }, $logger );
            trp_test_same( array( 17 => 'Vollständig' ), $result );
            trp_test_same( 2, $calls );
            trp_test_same( 2, count( $logger->charged ) );
            trp_test_same( 'truncated', TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'][0]['reason'] );
        };
    }
}

$tests['runner: single string stops after maximum-output retry'] = static function () {
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'Original' ), static function () use ( &$calls ) {
        ++$calls;
        return trp_test_response( '["Partial"]', 'length' );
    } ) );
    trp_test_same( 2, $calls );
};

$tests['runner: split ladder is bounded and preserves sparse keys'] = static function () {
    $calls = array();
    $chunk = array( 7 => 'A', 19 => 'B', 'last' => 'C' );
    $result = trp_test_run_chunk( $chunk, static function ( $part, $attempt ) use ( &$calls ) {
        $calls[] = array( $part, $attempt );
        if ( count( $calls ) <= 2 ) {
            return trp_test_response( '[', 'length' );
        }
        return trp_test_response( json_encode( array_values( $part ) ) );
    } );
    trp_test_same( $chunk, $result );
    trp_test_same( array(
        array( $chunk, 0 ), array( $chunk, 1 ),
        array( array( 7 => 'A', 19 => 'B' ), 1 ), array( array( 'last' => 'C' ), 1 ),
    ), $calls );
};

$tests['runner: truncated halves do not recurse further'] = static function () {
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B', 'C', 'D' ), static function () use ( &$calls ) {
        ++$calls;
        return trp_test_response( '[', 'length' );
    } ) );
    trp_test_same( 4, $calls );
};

$tests['runner: quota blocks the first request'] = static function () {
    $logger = new TRP_LLM_Test_Logger();
    $logger->max_charged_requests = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () {
        throw new RuntimeException( 'Request sent after quota was exhausted' );
    }, $logger ) );
};

$tests['runner: quota blocks an escalation'] = static function () {
    $logger = new TRP_LLM_Test_Logger();
    $logger->max_charged_requests = 1;
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B' ), static function () use ( &$calls ) {
        ++$calls;
        return trp_test_response( '[', 'length' );
    }, $logger ) );
    trp_test_same( 1, $calls );
};

$tests['runner: quota between halves keeps only the completed half'] = static function () {
    $logger = new TRP_LLM_Test_Logger();
    $logger->max_charged_requests = 3;
    $calls = 0;
    $result = trp_test_run_chunk( array( 8 => 'A', 33 => 'B' ), static function () use ( &$calls ) {
        return ++$calls <= 2 ? trp_test_response( '[', 'length' ) : trp_test_response( '["Erste"]' );
    }, $logger );
    trp_test_same( array( 8 => 'Erste' ), $result );
    trp_test_same( 3, $calls );
};

$tests['runner: active cooldown prevents a request and logs once'] = static function () {
    TRP_LLM_Engine_Cooldown::$reasons['openai'] = 'http-429';
    for ( $i = 0; $i < 2; ++$i ) {
        trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () {
            throw new RuntimeException( 'Request sent during cooldown' );
        } ) );
    }
    trp_test_same( 1, count( TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'] ) );
};

$tests['runner: first-half failure prevents sending the second half'] = static function () {
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B' ), static function () use ( &$calls ) {
        return ++$calls <= 2
            ? trp_test_response( '[', 'length' )
            : array( 'response' => array( 'code' => 429 ), 'body' => '{}' );
    } ) );
    trp_test_same( 3, $calls );
};

$tests['runner: spent budget prevents all sends and logs once'] = static function () {
    TRP_LLM_Request_Retry::$worthwhile = false;
    for ( $i = 0; $i < 2; ++$i ) {
        trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () {
            throw new RuntimeException( 'Request sent without budget' );
        } ) );
    }
    trp_test_same( 1, count( TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'] ) );
};

$tests['runner: budget exhausted by truncation prevents escalation'] = static function () {
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () use ( &$calls ) {
        ++$calls;
        TRP_LLM_Request_Retry::$worthwhile = false;
        return trp_test_response( '[', 'length' );
    } ) );
    trp_test_same( 1, $calls );
};

$tests['runner: empty input does not send'] = static function () {
    trp_test_same( array(), trp_test_run_chunk( array(), static function () {
        throw new RuntimeException( 'Empty chunk sent' );
    } ) );
};

foreach ( array( 'content_filter', 'refusal', 'tool_calls', 'function_call', 'tool_use', 'pause_turn' ) as $reason ) {
    $tests[ 'runner: rejects non-translation stop reason ' . $reason ] = static function () use ( $reason ) {
        $logger = new TRP_LLM_Test_Logger();
        $calls = 0;
        trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () use ( &$calls, $reason ) {
            ++$calls;
            return trp_test_response( '["Not a translation"]', $reason );
        }, $logger ) );
        trp_test_same( 1, $calls );
        trp_test_same( 1, count( $logger->charged ) );
        trp_test_same( 'non-translation-response', TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'][0]['reason'] );
    };
}

$tests['runner: refusal field overrides a matching array and stop reason'] = static function () {
    $body = array( 'choices' => array( array( 'message' => array( 'content' => '["No"]', 'refusal' => 'Declined' ), 'finish_reason' => 'stop' ) ) );
    trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () use ( $body ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
    } ) );
};

$tests['runner: count mismatch on a single string is charged but not retried'] = static function () {
    $logger = new TRP_LLM_Test_Logger();
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () use ( &$calls ) {
        ++$calls;
        return trp_test_response( '["one","two"]' );
    }, $logger ) );
    trp_test_same( 1, $calls );
    trp_test_same( 1, count( $logger->charged ) );
    trp_test_same( 'count-mismatch', TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'][0]['reason'] );
};

$tests['runner: count mismatch isolates the unusable string and keeps the rest'] = static function () {
    $logger = new TRP_LLM_Test_Logger();
    $sent = array();
    $result = trp_test_run_chunk( array( 3 => 'A', 7 => 'B', 9 => 'C' ), static function ( $part ) use ( &$sent ) {
        $sent[] = array_values( $part );

        // B is the string this model cannot count. Any batch carrying it comes
        // back one element short; any batch without it is well formed.
        if ( in_array( 'B', $part, true ) ) {
            return trp_test_response( json_encode( array_fill( 0, max( 1, count( $part ) - 1 ), 'DE' ) ) );
        }

        return trp_test_response( json_encode( array_fill( 0, count( $part ), 'DE' ) ) );
    }, $logger );

    // Sorted before comparing: recovery order follows the queue, which visits
    // the second half before the re-split halves of the first, and the order
    // strings are recovered in is not something callers should depend on.
    ksort( $result );
    trp_test_same( array( 3 => 'DE', 9 => 'DE' ), $result );
    // Four sends for a three string chunk: the batch, its two halves, and the
    // re-split first half until the allowance of three fragments runs out. B is
    // never asked alone, and does not need to be -- it is the string the model
    // cannot answer for, and it stays at status 0 either way.
    trp_test_same( array( array( 'A', 'B', 'C' ), array( 'A', 'B' ), array( 'C' ), array( 'A' ) ), $sent );
};

$tests['runner: isolation is bounded by the request allowance'] = static function () {
    $calls = 0;
    $filter = static function () {
        return 1;
    };
    add_filter( 'trp_llm_mismatch_split_requests', $filter );
    try {
        trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B', 'C', 'D' ), static function () use ( &$calls ) {
            ++$calls;
            return trp_test_response( '["one"]' );
        } ) );
    } finally {
        remove_filter( 'trp_llm_mismatch_split_requests', $filter );
    }

    // One full batch plus the single fragment the allowance paid for.
    trp_test_same( 2, $calls );
};

$tests['runner: a waiting render gets the smaller allowance'] = static function () {
    $calls = 0;
    // Any finite budget means a visitor is waiting, whatever the number is;
    // send_is_worthwhile() is what decides whether there is time for one more.
    TRP_LLM_Request_Retry::$budget = 9.5;
    trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B', 'C', 'D', 'E', 'F' ), static function () use ( &$calls ) {
        ++$calls;
        return trp_test_response( '["one"]' );
    } ) );

    // The batch, then two fragments, rather than the six an off-render caller
    // would have been allowed.
    trp_test_same( 3, $calls );
};

$tests['runner: isolation can be switched off'] = static function () {
    $calls = 0;
    add_filter( 'trp_llm_split_on_mismatch', '__return_false' );
    try {
        trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B' ), static function () use ( &$calls ) {
            ++$calls;
            return trp_test_response( '["Only one"]' );
        } ) );
    } finally {
        remove_filter( 'trp_llm_split_on_mismatch', '__return_false' );
    }

    trp_test_same( 1, $calls );
    trp_test_same( 'count-mismatch', TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'][0]['reason'] );
};

$tests['runner: an isolation pass writes one failure line, not one per fragment'] = static function () {
    $calls = 0;
    // One element too many at every size, and never an exact repetition, so the
    // count never matches and trim_repetition() cannot rescue a fragment either.
    trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B', 'C', 'D' ), static function ( $part ) use ( &$calls ) {
        ++$calls;
        $answer = array();
        for ( $i = 0; $i <= count( $part ); $i++ ) {
            $answer[] = 'DE' . $i;
        }

        return trp_test_response( json_encode( $answer ) );
    } ) );

    // The whole batch, then four fragments, which is the allowance.
    trp_test_same( 5, $calls );
    $reasons = array_column( TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'], 'reason' );
    trp_test_same( array( 'count-mismatch', 'count-mismatch-split' ), $reasons );
};

$tests['runner: a spent budget stops the isolation pass'] = static function () {
    $calls = 0;
    trp_test_same( array(), trp_test_run_chunk( array( 'A', 'B', 'C', 'D' ), static function () use ( &$calls ) {
        ++$calls;
        TRP_LLM_Request_Retry::$worthwhile = false;

        return trp_test_response( '["one"]' );
    } ) );

    trp_test_same( 1, $calls );
};

$tests['runner: invalid outer JSON is handled without warnings'] = static function () {
    trp_test_same( array(), trp_test_run_chunk( array( 'A' ), static function () {
        return array( 'response' => array( 'code' => 200 ), 'body' => '{invalid' );
    } ) );
    trp_test_same( 'no-content', TRP_LLM_Breadcrumb::$entries['trp_llm_chunk_failures'][0]['reason'] );
};

$tests['runner: exact repeated response is recovered'] = static function () {
    trp_test_same( array( 15 => 'Hallo' ), trp_test_run_chunk( array( 15 => 'Hello' ), static function () {
        return trp_test_response( '["Hallo","Hallo"]' );
    } ) );
};

$tests['runner: blank sibling remains retryable without discarding safe siblings'] = static function () {
    trp_test_same( array( 27 => 'Welt' ), trp_test_run_chunk( array( 4 => 'Hello', 27 => 'World' ), static function () {
        return trp_test_response( '["","Welt"]' );
    } ) );
};

$tests['runner: enabled vendor log receives metadata without raw source'] = static function () {
    $logger = new TRP_LLM_Test_Logger();
    trp_test_run_chunk( array( 7 => 'Hello' ), static function () {
        return trp_test_response( '["Hallo"]' );
    }, $logger, array( 'machine_translation_log' => 'yes' ) );
    trp_test_same( 1, count( $logger->logs ) );
    trp_test_same( serialize( array( '[redacted: 1 strings]' ) ), $logger->logs[0]['strings'] );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
    trp_test_reset();
    try {
        $test();
        echo "PASS {$name}\n";
    } catch ( Throwable $error ) {
        ++$failures;
        fwrite( STDERR, "FAIL {$name}: {$error->getMessage()}\n" );
    }
}
printf( "\n%d tests, %d failures (PHP %s)\n", count( $tests ), $failures, PHP_VERSION );
exit( $failures > 0 ? 1 : 0 );
