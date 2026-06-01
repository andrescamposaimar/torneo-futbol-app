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
 *   (already bound in Plugin.php:118 to prode_recompute_rankings_cron).
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
     */
    public static function run(): void {
        global $wpdb;

        $repo      = new RankingRepository( $wpdb );
        $computer  = new RankingComputer();
        $scoreRepo = new ScoreRepository( $wpdb );
        $tenantId  = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $now       = current_time( 'mysql' );

        foreach ( $repo->listEvaluatedFechaIds( $tenantId ) as $fechaId ) {
            // Gate: skip fecha that still has unscored matches (defensive guard).
            if ( $scoreRepo->countUnscoredMatches( $fechaId ) > 0 ) {
                continue;
            }

            $rows = $repo->aggregateByFecha( $fechaId );
            if ( empty( $rows ) ) {
                continue;
            }

            $ranked = $computer->assignRanks( $rows );
            $repo->upsertFechaCache( $fechaId, $ranked, $now );
        }

        // Observability hook — always fired, mirrors EvaluatorCron pattern.
        do_action( 'prode_ranking_cron_ran' );
    }
}
