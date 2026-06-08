<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Scoring\FechaEvaluator;
use EntreRedes\Prode\Scoring\ScoreRepository;

/**
 * Cron handler: evaluates match predictions and writes prode_scores rows.
 *
 * Design (mirrors FechaCreationCron, ADR-G3-1):
 *   The WP hook binds the STATIC run() entrypoint — that signature is frozen
 *   (already bound in Plugin.php:105 to prode_evaluate_matches_cron).
 *   All logic lives in FechaEvaluator::evaluateFecha(); run() is a thin wiring
 *   adapter that instantiates collaborators and resolves the locked fecha.
 *
 * FechaEvaluator is the shared brain called from BOTH this cron and the
 * EvaluationController REST endpoint (ADR-G3-1). No logic is duplicated here.
 */
class EvaluatorCron {

    /**
     * WP hook entrypoint — keep this signature unchanged.
     *
     * Instantiates real collaborators and calls FechaEvaluator::evaluateFecha()
     * for the active locked fecha, if any (EC-1: no locked fecha → exit clean).
     */
    public static function run(): void {
        global $wpdb;

        $scoreRepo  = new ScoreRepository( $wpdb );
        $predRepo   = new PredictionRepository( $wpdb );
        $fechaRepo  = new FechaRepository( $wpdb );

        // Production dispatcher: internal REST request to /partidos (ADR-G3-5).
        $dispatcher = static fn( \WP_REST_Request $req ) => rest_do_request( $req );

        $evaluator = new FechaEvaluator( $scoreRepo, $predRepo, $fechaRepo, $dispatcher );

        // Resolve the active locked fecha via FechaRepository::findActiveFecha().
        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $seasonId = 0; // Resolved per-fecha; evaluateFecha reads season_id from the fecha row.

        // The 'locked' state is DERIVED, never persisted: a fecha is locked when
        // now >= locked_at and it has not yet been evaluated (mirrors
        // LockComputer::deriveState). The persisted state column only ever holds
        // 'open' or 'evaluated', so the previous `state = 'locked'` filter matched
        // nothing and evaluation never ran.
        //
        // Evaluate EVERY due fecha, not just the oldest: a fecha that can never
        // complete (e.g. a postponed match that never gets a final result) stays
        // un-evaluated forever, and a single-row LIMIT would let it permanently
        // starve newer, complete fechas. evaluateFecha() is idempotent, so
        // re-touching a still-pending fecha each run is a harmless no-op.
        $now    = current_time( 'mysql' );
        $dueIds = $fechaRepo->listDueFechaIds( $tenantId, $now );

        if ( empty( $dueIds ) ) {
            // EC-1: nothing due — exit cleanly.
            do_action( 'prode_evaluator_cron_ran' );
            return;
        }

        foreach ( $dueIds as $fechaId ) {
            $evaluator->evaluateFecha( $fechaId );
        }

        do_action( 'prode_evaluator_cron_ran' );
    }
}

