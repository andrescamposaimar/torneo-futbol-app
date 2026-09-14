<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

use EntreRedes\Prode\Scoring\RankingComputer;
use EntreRedes\Prode\Scoring\RankingRepository;
use EntreRedes\Prode\Scoring\ScoreRepository;

/**
 * Cron handler: recomputes per-fecha ranking caches after evaluation.
 *
 * Design (mirrors EvaluatorCron — thin adapter pattern, ADR-G4-1):
 *   The WP hook binds the STATIC run() entrypoint — that signature is frozen
 *   (bound to prode_recompute_rankings_cron in Plugin::boot()).
 *   run() instantiates all collaborators from globals and delegates all logic
 *   to RankingComputer + RankingRepository.
 *
 * Idempotent: re-running on the same evaluated fechas overwrites cache rows
 * via the SELECT-then-INSERT/UPDATE upsert in RankingRepository.
 *
 * Gate: a fecha with state='evaluated' AND countUnscoredMatches > 0 is
 * skipped — defensive guard reusing ScoreRepository::countUnscoredMatches
 * (same gate as the EvaluatorCron / FechaEvaluator path).
 */
class RankingCron {

    /**
     * WP hook entrypoint — keep this signature unchanged.
     *
     * Processes ALL evaluated fechas for the active tenant in one pass.
     * Fires 'prode_ranking_cron_ran' after the loop (even when no fechas
     * qualify), providing an observability hook for tests and monitoring.
     *
     * Action signature (ADR-G8-1): 'prode_ranking_cron_ran' now carries three
     * int args — $processed, $skippedUnscored, $skippedEmpty — counting the
     * fechas that were actually upserted vs. the two distinct skip reasons in
     * the loop below. This is backward compatible: an `add_action` callback
     * registered with fewer `accepted_args` (or none at all, the pre-existing
     * default of 1) simply never sees the new args and keeps working exactly
     * as before. Added so RecomputeRankingsController can report a rebuild
     * summary to the operator who triggered it — without this hook, a
     * REST-triggered rebuild had no way to know what it actually did.
     */
    public static function run(): void {
        global $wpdb;

        $repo      = new RankingRepository( $wpdb );
        $computer  = new RankingComputer();
        $scoreRepo = new ScoreRepository( $wpdb );
        $tenantId  = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $now       = current_time( 'mysql' );

        $processed       = 0;
        $skippedUnscored = 0;
        $skippedEmpty    = 0;

        foreach ( $repo->listEvaluatedFechaIds( $tenantId ) as $fechaId ) {
            // Gate: skip fecha that still has unscored matches (defensive guard).
            if ( $scoreRepo->countUnscoredMatches( $fechaId ) > 0 ) {
                ++$skippedUnscored;
                continue;
            }

            $rows = $repo->aggregateByFecha( $fechaId );
            if ( empty( $rows ) ) {
                ++$skippedEmpty;
                continue;
            }

            $ranked = $computer->assignRanks( $rows );
            $repo->upsertFechaCache( $fechaId, $ranked, $now );
            ++$processed;
        }

        // Observability hook — always fired, mirrors EvaluatorCron pattern.
        do_action( 'prode_ranking_cron_ran', $processed, $skippedUnscored, $skippedEmpty );
    }
}
