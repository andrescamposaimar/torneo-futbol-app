<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Scoring\FechaEvaluator;
use EntreRedes\Prode\Scoring\ScoreRepository;

/**
 * Cron handler: repairs an already-evaluated fecha whose result was corrected
 * in SportsPress after evaluation (ADR-G7-1).
 *
 * Design (mirrors EvaluatorCron — thin adapter pattern):
 *   ResultChangeListener::onSavePost() schedules the 'prode_reevaluate_fecha'
 *   event with the affected fecha_id once it detects a played match's score
 *   diverging from the snapshot an already-'evaluated' fecha was scored
 *   against. run() is the thin WP-Cron adapter bound to that hook
 *   (Plugin.php); all evaluation logic lives in FechaEvaluator::evaluateFecha(),
 *   the same shared brain used by EvaluatorCron and EvaluationController.
 *
 * Why this is allowed to bypass EvaluationController's `fecha_not_locked` guard:
 *   That guard is operator-facing POLICY guarding a MANUAL evaluation request —
 *   it exists to stop an admin from evaluating a fecha before it is due (state
 *   != 'evaluated' yet, or now < locked_at). This cron is the opposite case: a
 *   SYSTEM-triggered repair of a fecha ResultChangeListener already confirmed
 *   is 'evaluated', run only after detecting the persisted result diverged from
 *   what SportsPress now reports. Calling evaluateFecha() directly is safe
 *   because it is idempotent by construction (ADR-G3-1): it re-reads live
 *   results, re-snapshots real_score_* (FechaRepository::snapshotResult),
 *   upserts every (user, fecha, match) score row via ScoreRepository's unique
 *   key, and re-derives fecha_state from the resulting pending count — running
 *   it twice on the same corrected data yields the same rows, not duplicates.
 */
class ReevaluateFechaCron {

    /**
     * WP-Cron hook name. Shared with ResultChangeListener, which schedules it —
     * keep the literal in exactly one place so a rename cannot silently split
     * the scheduler from the handler.
     */
    public const HOOK = 'prode_reevaluate_fecha';

    /**
     * WP hook entrypoint bound to 'prode_reevaluate_fecha' — keep this
     * signature unchanged. WordPress passes wp_schedule_single_event()'s single
     * $args element ([ $fecha_id ]) as this one accepted argument.
     */
    public static function run( int $fecha_id ): void {
        global $wpdb;

        $scoreRepo = new ScoreRepository( $wpdb );
        $predRepo  = new PredictionRepository( $wpdb );
        $fechaRepo = new FechaRepository( $wpdb );

        // Production dispatcher: internal REST request to /partidos (ADR-G3-5),
        // identical to EvaluatorCron. ResultChangeListener already purged the
        // /partidos transients before scheduling this event (ADR-G7-2), so this
        // call is guaranteed to read the corrected result, not a cached one.
        $dispatcher = static fn( \WP_REST_Request $req ) => rest_do_request( $req );

        $evaluator = new FechaEvaluator( $scoreRepo, $predRepo, $fechaRepo, $dispatcher );

        $evaluator->evaluateFecha( $fecha_id );

        // Observability hook — mirrors 'prode_ranking_cron_ran' (RankingCron).
        do_action( 'prode_reevaluate_fecha_ran', $fecha_id );
    }
}
