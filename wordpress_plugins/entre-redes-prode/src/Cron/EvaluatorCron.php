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

        // findActiveFecha returns open/locked; we need specifically 'locked'.
        // Query directly for the locked fecha to avoid evaluating 'open' ones.
        $lockedFecha = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_fechas
                  WHERE tenant_id = %s AND state = 'locked'
                  ORDER BY locked_at ASC, created_at DESC
                  LIMIT 1",
                $tenantId
            ),
            ARRAY_A
        );

        if ( empty( $lockedFecha ) ) {
            // EC-1: no locked fecha — exit cleanly, no hook fired.
            do_action( 'prode_evaluator_cron_ran' );
            return;
        }

        $evaluator->evaluateFecha( (int) $lockedFecha['id'] );

        do_action( 'prode_evaluator_cron_ran' );
    }
}

