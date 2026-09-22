<?php
/**
 * What happens to one chunk between sending it and storing what came back.
 *
 * This lived in four engine classes as four byte-identical copies, differing in
 * an engine slug and one array path. That is the same defect the plugin was
 * being repaired for: the default model had three copies, they drifted, and an
 * unset setting quietly billed the wrong one. Retry, cooldown, quota accounting
 * and truncation handling are policy, and policy in four places is policy that
 * will be three different policies after the next fix.
 *
 * Nothing here needs inheritance. Every per-engine part reaches this through a
 * parameter: the slug names the engine, the sender closes over the engine's own
 * send_request(), and the two response shapes are already absorbed by
 * TRP_LLM_Request_Shape.
 *
 * There are two ladders here and they answer two different complaints. The
 * escalation ladder answers an answer that ran out of room. The isolation pass,
 * isolate_after_mismatch(), answers an answer that is complete and has the wrong
 * number of elements in it -- which cannot be aligned, and so used to cost the
 * whole batch. See that method for why it is counted in requests.
 *
 * The isolation pass is a workaround and worth naming as one. A positional array
 * is a lossy shape for this: a short answer carries no clue about which element
 * it dropped, so the only way to learn that is to ask smaller questions. Asking
 * for a position-keyed object instead would let one short answer name its own
 * gap, and TRP_LLM_Response_Normalizer::parse() already understands that shape.
 * That is a change to what every request asks every model for, so it belongs to
 * its own measurement across the four engines rather than to this one.
 *
 * The escalation deserves its own note, because the obvious version of it does
 * not work. A truncated answer used to be halved. Halving the chunk halves the
 * payload, and the output ceiling is derived from the payload, so the second
 * attempt got exactly the same number of tokens per source byte as the first and
 * failed for exactly the same reason. What raises tokens per byte is either a
 * higher ceiling for the same text, or the same ceiling for less text. So the
 * first escalation asks for the maximum ceiling, and only when that is already
 * spent does halving buy anything, because half the payload under a fixed
 * maximum really is double the room.
 *
 * Every escalation is gated on the render budget. Translation runs inline during
 * page render on a prefork origin, so an unbounded chain of escalations is a
 * worker held open against a live visitor. When the budget is spent the strings
 * are simply left at status 0 and offered again on a later render.
 *
 * @package translatepress-llm-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Chunk_Runner {

    /**
     * The rungs of the truncation ladder, in the order they are climbed.
     *
     * A single rung rather than an attempt counter plus a was-this-split flag.
     * Two flags can express four states and this ladder only has three, so the
     * fourth, a first attempt that is somehow already a half, was reachable by a
     * careless caller and meaningless if reached.
     */
    const RUNG_DERIVED = 0;
    const RUNG_MAX     = 1;
    const RUNG_HALVED  = 2;

    /**
     * A fragment produced by the isolation pass after a wrong element count.
     *
     * It is a rung rather than a flag on the truncation ladder because it
     * answers a different question. Truncation is an answer that ran out of
     * room, and the cure is room. A wrong element count is an answer that is
     * complete and unusable, and the only cure is a smaller question, because
     * nothing in a short array says which element is the missing one.
     */
    const RUNG_SPLIT   = 3;

    /**
     * Translate one chunk, escalating and splitting as the answers justify.
     *
     * @param array    $chunk       Strings keyed as TranslatePress keys them.
     * @param array    $context     Context from TRP_LLM_Request_Shape::context().
     * @param object   $logger      The vendor's TRP_Machine_Translator_Logger.
     * @param array    $mt_settings The trp_machine_translation_settings array.
     * @param callable $sender      fn( array $chunk, int $attempt ) => wp_remote_post() result.
     *
     * @return array Translations safe to store, keyed like $chunk. Everything
     *               else is omitted, so the row stays at status 0 and is offered
     *               again rather than being stored wrong.
     */
    public static function run( array $chunk, array $context, $logger, array $mt_settings, $sender ) {
        return TRP_LLM_Translation_State::run( $chunk, $context, $mt_settings, $sender,
            static function ( $pending, $guarded_sender ) use ( $context, $logger, $mt_settings ) {
                $failed = array();
                $translations = self::attempt( $pending, $context, $logger, $mt_settings, $guarded_sender, self::RUNG_DERIVED, $failed );
                return array( 'translations' => $translations, 'failed' => $failed );
            }
        );
    }

    /**
     * One send, plus whatever the answer justifies doing next.
     *
     * @param array    $chunk       Strings for this attempt.
     * @param array    $context     Request context.
     * @param object   $logger      Vendor logger.
     * @param array    $mt_settings Machine translation settings.
     * @param callable $sender      Sender closure.
     * @param int      $rung        One of the RUNG_ constants.
     *
     * @return array
     */
    private static function attempt( array $chunk, array $context, $logger, array $mt_settings, $sender, $rung, array &$failed ) {
        $engine  = $context['engine'];
        $attempt = self::RUNG_DERIVED === $rung ? 0 : 1;

        // Every rung is a new paid request. The outer engine loop cannot enforce
        // these limits between recursive retries or between the two halves.
        if ( array() === $chunk || $logger->quota_exceeded() ) {
            return array();
        }

        if ( '' !== TRP_LLM_Engine_Cooldown::active( $engine ) ) {
            self::log_once( $engine, $engine, 'cooldown', count( $chunk ), 0, '' );

            return array();
        }

        // The budget was checked before retries and before escalations and never
        // before the first send, which is the one that always happens. Every cURL
        // 28 in trp_llm_http_failures entered here with a budget that could not
        // hold a request and left with a paid, abandoned completion.
        //
        // Leaving the chunk untranslated is not a quiet failure. The strings stay
        // at status 0, so a later render tries again, and a later render is not
        // competing for this visitor's budget.
        if ( ! TRP_LLM_Request_Retry::send_is_worthwhile() ) {
            self::log_once( $engine, $engine . ':budget', 'budget-spent', count( $chunk ), 0, '' );

            return array();
        }

        // The caller built this context before the first send, so its attempt was
        // 0 and stayed 0 while the ladder was climbed. Anything reading it, a log
        // line here or a site filter downstream, would have been told every
        // escalated send was a first send.
        $context['attempt'] = $attempt;
        $context['strings'] = array_values( $chunk );

        $response = TRP_LLM_Request_Retry::send(
            function () use ( $sender, $chunk, $attempt ) {
                return call_user_func( $sender, $chunk, $attempt );
            }
        );

        if ( is_wp_error( $response ) && 0 === strpos( $response->get_error_code(), 'trp_llm_' ) ) {
            // Local configuration/storage failures are not provider outages.
            TRP_LLM_Response_Normalizer::log_chunk_failure( $engine, $response->get_error_code(), count( $chunk ), 0, '' );
            return array();
        }

        self::log_request( $logger, $mt_settings, $chunk, $response, $context );

        $reason = TRP_LLM_Http_Failure_Log::record( $engine, $context['model'], $context['target_language'], $response );

        if ( 'ok' !== $reason ) {
            TRP_LLM_Engine_Cooldown::start( $engine, $reason, $response, TRP_LLM_Request_Retry::classify( $response ) );

            return array();
        }

        // The charge is incurred by the response, not by its usability. This call
        // used to sit inside the count match branch below, so a truncated or
        // wrong shaped answer was billed by the provider and counted by nobody.
        // On 2026-08-17 that hid 44 paid batches from the quota in three minutes.
        $logger->count_towards_quota( $chunk );
        // Only a paid HTTP-success response creates content-failure backoff.
        // Successful keys override this below in the state coordinator.
        $failed += array_fill_keys( array_keys( $chunk ), true );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        TRP_LLM_Request_Shape::record_cost( $engine, $body );

        $content = TRP_LLM_Request_Shape::message_content( $body );

        // Refusals and tool hand-offs are not translations, even when their text
        // happens to parse as a matching JSON array. Do not retry them inline.
        $finish_reason = TRP_LLM_Request_Shape::finish_reason( $body );
        $not_translation = array( 'content_filter', 'refusal', 'tool_calls', 'function_call', 'tool_use', 'pause_turn' );

        if ( ! empty( $body['choices'][0]['message']['refusal'] ) || in_array( $finish_reason, $not_translation, true ) ) {
            TRP_LLM_Response_Normalizer::log_chunk_failure( $engine, 'non-translation-response', count( $chunk ), 0, '' );

            return array();
        }

        // A syntactically valid array is not proof that the provider finished.
        // Treat even a matching-count answer as incomplete when the provider
        // reports its token limit, including responses with no text at all.
        if ( TRP_LLM_Request_Shape::was_truncated( $body ) ) {
            return self::escalate(
                $chunk,
                $context,
                $logger,
                $mt_settings,
                $sender,
                $rung,
                TRP_LLM_Response_Normalizer::parse( $content ),
                null === $content ? '' : $content,
                $failed
            );
        }

        if ( null === $content ) {
            TRP_LLM_Response_Normalizer::log_chunk_failure(
                $engine,
                'no-content',
                count( $chunk ),
                0,
                wp_remote_retrieve_body( $response )
            );

            return array();
        }

        $translations = TRP_LLM_Response_Normalizer::parse( $content );

        // Recover exact repeated batches only after ruling out truncation.
        // Similar or partial copies fall through to count-mismatch instead of
        // being stored as though the first copy were known to be complete.
        if ( count( $translations ) > count( $chunk ) ) {
            $trimmed = TRP_LLM_Response_Normalizer::trim_repetition( $translations, count( $chunk ) );

            if ( null !== $trimmed ) {
                $translations = $trimmed;
            }
        }

        if ( count( $translations ) === count( $chunk ) ) {
            return TRP_LLM_Placeholder_Guard::filter_chunk( $chunk, $translations, $engine );
        }

        // An answer that parsed to nothing and an answer with the wrong number of
        // elements have different causes and different fixes, so they carry
        // different reasons. Both used to be logged as count-mismatch, which is
        // why a batch blanked by a response filter read exactly like a model that
        // had changed its output format.
        $mismatch = array() === $translations ? 'empty-array' : 'count-mismatch';

        // A fragment is already inside an isolation pass. It does not start a
        // second one, and it does not get a line of its own every time, because
        // one pass over a ten string chunk can produce ten of these.
        if ( self::RUNG_SPLIT === $rung ) {
            self::log_once(
                $engine,
                $engine . ':split:' . $mismatch,
                $mismatch . '-split',
                count( $chunk ),
                count( $translations ),
                $content
            );

            return array();
        }

        TRP_LLM_Response_Normalizer::log_chunk_failure(
            $engine,
            $mismatch,
            count( $chunk ),
            count( $translations ),
            $content
        );

        // Measured on this site on 2026-09-22: count-mismatch was 23 of the 50
        // entries in the failure ring, almost all of them expected=5 received=4.
        // Discarding the batch threw four usable translations away to be safe
        // about one missing element, and the filler logged 74 to 88 failures per
        // pass of roughly 1,200 rows because of it. Nothing here can align four
        // answers against five questions, so the batch really is unusable -- but
        // the two halves of it are two smaller questions, and a single string is
        // a question whose answer cannot be misaligned at all.
        //
        // A half produced by the truncation ladder does not start this. Its
        // parent has already spent an escalation and a split on this chunk, and
        // stacking a second allowance on top of that makes the worst case cost
        // of one bad chunk hard to state.
        if ( self::RUNG_HALVED !== $rung
            && count( $chunk ) > 1
            && apply_filters( 'trp_llm_split_on_mismatch', true, $context ) ) {
            return self::isolate_after_mismatch( $chunk, $context, $logger, $mt_settings, $sender, $failed );
        }

        return array();
    }

    /**
     * Most fragments a render may pay for after a wrong element count.
     *
     * A render has one visitor waiting and ten seconds to spend, so it bisects
     * far enough to rescue the easy majority of a batch and no further. A caller
     * with no deadline -- WP-CLI, cron, the guarded backfill scripts -- has no
     * visitor to protect and gets the full allowance instead.
     */
    const RENDER_SPLIT_REQUESTS = 2;

    /**
     * Ask the same model smaller questions until the unusable element is alone.
     *
     * The ceiling is counted in requests rather than in recursion depth, because
     * requests are what this costs. Off the render path that is one extra request
     * per string, which is enough to isolate a single bad element out of ten and
     * caps a model that mismatches at every size at roughly twice what the chunk
     * already cost. On the render path it is RENDER_SPLIT_REQUESTS, because
     * count-mismatch is not an edge case here -- it was 23 of 50 failures when
     * this was written -- and doubling the blocking calls of the commonest
     * failure is not something to hand a live visitor by default.
     *
     * @param array    $chunk       Strings the wrong element count was for.
     * @param array    $context     Request context.
     * @param object   $logger      Vendor logger.
     * @param array    $mt_settings Machine translation settings.
     * @param callable $sender      Sender closure.
     * @param array    $failed      Keys with an observed paid content failure.
     *
     * @return array Translations recovered from the fragments.
     */
    private static function isolate_after_mismatch( array $chunk, array $context, $logger, array $mt_settings, $sender, array &$failed ) {
        $engine  = $context['engine'];
        $on_render = INF !== TRP_LLM_Request_Retry::remaining_budget();
        $allowance = (int) apply_filters(
            'trp_llm_mismatch_split_requests',
            $on_render ? self::RENDER_SPLIT_REQUESTS : count( $chunk ),
            $chunk,
            $context
        );

        if ( $allowance < 1 ) {
            return array();
        }

        $queue     = self::halve( $chunk );
        $recovered = array();

        while ( array() !== $queue && $allowance > 0 ) {
            // attempt() asks all three of these itself and answers with an
            // empty array, so without them a cooled down engine, an exhausted
            // quota or a spent render budget would burn the whole allowance on
            // requests never issued, and re-queue halves for each one. These
            // are attempt()'s own entry conditions and want to stay its own
            // entry conditions: a fourth one added there belongs here too.
            if ( $logger->quota_exceeded()
                || '' !== TRP_LLM_Engine_Cooldown::active( $engine )
                || ! TRP_LLM_Request_Retry::send_is_worthwhile() ) {
                break;
            }

            $part = array_shift( $queue );
            --$allowance;

            $translated = self::attempt( $part, $context, $logger, $mt_settings, $sender, self::RUNG_SPLIT, $failed );

            if ( array() !== $translated ) {
                $recovered += $translated;

                continue;
            }

            // Anything that produced nothing is halved again, down to single
            // strings. A one string question whose answer has the wrong element
            // count has nothing left to rescue, and halve() returns nothing for
            // it, so the queue drains rather than looping.
            foreach ( self::halve( $part ) as $half ) {
                $queue[] = $half;
            }
        }

        return $recovered;
    }

    /**
     * The two halves of a chunk, or nothing when it cannot be divided.
     *
     * @param array $chunk Strings keyed as TranslatePress keys them.
     *
     * @return array<int, array>
     */
    private static function halve( array $chunk ) {
        if ( count( $chunk ) < 2 ) {
            return array();
        }

        return array_chunk( $chunk, (int) ceil( count( $chunk ) / 2 ), true );
    }

    /**
     * What each rung is called in the chunk failure log.
     *
     * Three names because they call for three different answers. truncated is
     * routine and self healing. truncated-at-max means the widest ceiling this
     * plugin will ask for was not enough for a chunk of this size, so the chunk
     * size wants lowering. truncated-half means even half a chunk at the widest
     * ceiling overran, which points at the model rather than the size.
     */
    const RUNG_REASONS = array(
        self::RUNG_DERIVED => 'truncated',
        self::RUNG_MAX     => 'truncated-at-max',
        self::RUNG_HALVED  => 'truncated-half',
        self::RUNG_SPLIT   => 'truncated-split',
    );

    /**
     * Respond to an answer that ran out of room.
     *
     * @param array    $chunk        Strings for this attempt.
     * @param array    $context      Request context.
     * @param object   $logger       Vendor logger.
     * @param array    $mt_settings  Machine translation settings.
     * @param callable $sender       Sender closure.
     * @param int      $rung         Rung that just truncated.
     * @param array    $translations What did parse, for the log.
     * @param string   $content      Raw content, for the log.
     *
     * @return array
     */
    private static function escalate( array $chunk, array $context, $logger, array $mt_settings, $sender, $rung, $translations, $content, array &$failed ) {
        TRP_LLM_Response_Normalizer::log_chunk_failure(
            $context['engine'],
            self::RUNG_REASONS[ $rung ],
            count( $chunk ),
            count( $translations ),
            $content
        );

        // No budget check here. attempt() asks the same question at its own
        // entrance and answers it the same way, and it also writes the
        // budget-spent breadcrumb that a guard at this level would swallow.
        if ( self::RUNG_DERIVED === $rung ) {
            return self::attempt( $chunk, $context, $logger, $mt_settings, $sender, self::RUNG_MAX, $failed );
        }

        // A fragment of an isolation pass stops here. Its own allowance is held
        // by the pass that created it, and halving it again outside that
        // allowance is spending nobody is counting.
        if ( self::RUNG_HALVED === $rung || self::RUNG_SPLIT === $rung || count( $chunk ) < 2 ) {
            return array();
        }

        $halves    = self::halve( $chunk );
        $recovered = array();

        foreach ( $halves as $half ) {
            $recovered += self::attempt( $half, $context, $logger, $mt_settings, $sender, self::RUNG_HALVED, $failed );
        }

        return $recovered;
    }

    /**
     * Record a failure once per request, however many chunks meet it.
     *
     * Three callers, all of them a condition that is a property of the request
     * rather than of the chunk: a cooled down engine, a spent render budget, and
     * an isolation pass that is not recovering anything. A page carrying hundreds
     * of pending strings enters each of those once per chunk, and fifty identical
     * rows in a fifty slot ring buffer is a log that has forgotten everything
     * else, written with a read-modify-write of wp_options per row during exactly
     * the incident that wanted to be cheap.
     *
     * @param string $engine   Engine slug.
     * @param string $key      What counts as "the same skip" for this request.
     * @param string $reason   Reason recorded in the chunk failure ring.
     * @param int    $expected Strings asked for.
     * @param int    $received Translations understood.
     * @param string $content  Raw answer, for the sample.
     *
     * @return void
     */
    private static function log_once( $engine, $key, $reason, $expected, $received, $content ) {
        if ( ! TRP_LLM_Engine_Cooldown::note_skip( $key ) ) {
            return;
        }

        TRP_LLM_Response_Normalizer::log_chunk_failure( $engine, $reason, $expected, $received, $content );
    }

    /**
     * Hand the vendor logger a request, but only when it will keep it.
     *
     * TRP_Machine_Translator_Logger::log() discards everything unless the
     * machine_translation_log setting is on, and this site keeps it off. The two
     * serialize() calls on the way in ran on every chunk regardless, one of them
     * over a whole HTTP response body, for a row that was never written.
     *
     * @param object $logger      Vendor logger.
     * @param array  $mt_settings Machine translation settings.
     * @param array  $chunk       Strings sent.
     * @param mixed  $response    Response received.
     * @param array  $context     Request context.
     *
     * @return void
     */
    private static function log_request( $logger, array $mt_settings, array $chunk, $response, array $context ) {
        if ( ! isset( $mt_settings['machine_translation_log'] ) || 'yes' !== $mt_settings['machine_translation_log'] ) {
            return;
        }

        if ( ! apply_filters( 'trp_llm_diagnostics_enabled', true ) ) {
            return;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $strings = array( '[redacted: ' . count( $chunk ) . ' strings]' );
        if ( apply_filters( 'trp_llm_diagnostic_content', false ) ) {
            $strings = array_map( array( 'TRP_LLM_Breadcrumb', 'excerpt' ), array_values( $chunk ) );
        }
        $logger->log( array(
            'strings' => serialize( $strings ),
            'response' => serialize( array(
                'http' => (int) wp_remote_retrieve_response_code( $response ),
                'finish_reason' => TRP_LLM_Request_Shape::finish_reason( $body ),
            ) ),
            'lang_source' => $context['source_language'],
            'lang_target' => $context['target_language'],
        ) );
    }
}
