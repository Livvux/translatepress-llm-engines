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
     * Translate one chunk, escalating once and then splitting once.
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
        return self::attempt( $chunk, $context, $logger, $mt_settings, $sender, self::RUNG_DERIVED );
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
    private static function attempt( array $chunk, array $context, $logger, array $mt_settings, $sender, $rung ) {
        $engine  = $context['engine'];
        $attempt = self::RUNG_DERIVED === $rung ? 0 : 1;

        // The caller built this context before the first send, so its attempt was
        // 0 and stayed 0 while the ladder was climbed. Anything reading it, a log
        // line here or a site filter downstream, would have been told every
        // escalated send was a first send.
        $context['attempt'] = $attempt;

        $response = TRP_LLM_Request_Retry::send(
            function () use ( $sender, $chunk, $attempt ) {
                return call_user_func( $sender, $chunk, $attempt );
            }
        );

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

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        TRP_LLM_Request_Shape::record_cost( $engine, $body );

        $content = TRP_LLM_Request_Shape::message_content( $body );

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

        if ( count( $translations ) === count( $chunk ) ) {
            return TRP_LLM_Placeholder_Guard::filter_chunk( $chunk, $translations, $engine );
        }

        if ( TRP_LLM_Request_Shape::was_truncated( $body ) ) {
            return self::escalate( $chunk, $context, $logger, $mt_settings, $sender, $rung, $translations, $content );
        }

        // An answer that parsed to nothing and an answer with the wrong number of
        // elements have different causes and different fixes, so they carry
        // different reasons. Both used to be logged as count-mismatch, which is
        // why a batch blanked by a response filter read exactly like a model that
        // had changed its output format.
        TRP_LLM_Response_Normalizer::log_chunk_failure(
            $engine,
            array() === $translations ? 'empty-array' : 'count-mismatch',
            count( $chunk ),
            count( $translations ),
            $content
        );

        return array();
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
    );

    /**
     * Whether one more send still fits inside this visitor's render budget.
     *
     * The sleep is not the expensive part. The request that follows it carries
     * its own timeout, and that is what holds a prefork worker open long after
     * the deadline has passed.
     *
     * @return bool
     */
    private static function another_send_fits() {
        return TRP_LLM_Request_Retry::fits_in_budget( TRP_LLM_Request_Retry::request_timeout() );
    }

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
    private static function escalate( array $chunk, array $context, $logger, array $mt_settings, $sender, $rung, $translations, $content ) {
        TRP_LLM_Response_Normalizer::log_chunk_failure(
            $context['engine'],
            self::RUNG_REASONS[ $rung ],
            count( $chunk ),
            count( $translations ),
            $content
        );

        // Leaving it here is not a quiet failure. The strings stay at status 0,
        // so a later render tries again, and a later render is not competing for
        // this visitor's budget.
        if ( ! self::another_send_fits() ) {
            return array();
        }

        if ( self::RUNG_DERIVED === $rung ) {
            return self::attempt( $chunk, $context, $logger, $mt_settings, $sender, self::RUNG_MAX );
        }

        if ( self::RUNG_HALVED === $rung || count( $chunk ) < 2 ) {
            return array();
        }

        $halves    = array_chunk( $chunk, (int) ceil( count( $chunk ) / 2 ), true );
        $recovered = array();

        foreach ( $halves as $half ) {
            if ( ! self::another_send_fits() ) {
                break;
            }

            $recovered += self::attempt( $half, $context, $logger, $mt_settings, $sender, self::RUNG_HALVED );
        }

        return $recovered;
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

        $logger->log( array(
            'strings'     => serialize( $chunk ),
            'response'    => serialize( $response ),
            'lang_source' => $context['source_language'],
            'lang_target' => $context['target_language'],
        ) );
    }
}
