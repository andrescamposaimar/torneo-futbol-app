<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Cron;

use EntreRedes\Prode\Cron\RankingCron;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Scoring\RankingRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RankingCron::run().
 *
 * Uses the SQLite shim. Isolation via setUp/tearDown DELETE.
 *
 * Hook assertion: did_action('prode_ranking_cron_ran') > 0 after run().
 * (The shim's add_action() is a no-op; do_action() increments $GLOBALS['_prode_test_actions'].)
 *
 * Counter assertion (ADR-G8-1): 'prode_ranking_cron_ran' now carries
 * ($processed, $skippedUnscored, $skippedEmpty). captureCronCounters()
 * registers a real listener via the shim's add_action()/do_action() pair so
 * these tests observe the args exactly the way RecomputeRankingsController
 * will, rather than re-deriving the counts by hand.
 *
 * Spec coverage: CRN-01..06.
 */
class RankingCronTest extends TestCase {

    private RankingRepository $rankingRepo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $GLOBALS['_prode_test_actions'] = [];

        $this->rankingRepo = new RankingRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
    }

    // -------------------------------------------------------------------------
    // Seeding helpers
    // -------------------------------------------------------------------------

    private function seedUser( int $userId ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'           => $userId,
                'tenant_id'    => 'test_tenant',
                'dni'          => "dni_{$userId}",
                'provider'     => 'google',
                'provider_id'  => "gid_{$userId}",
                'display_name' => "User {$userId}",
                'created_at'   => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * Seed a prode_fechas row. Returns the fecha_id.
     */
    private function seedFecha( string $state ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'    => 'test_tenant',
                'season_id'    => 359,
                'locked_at'    => '2026-05-30 10:00:00',
                'state'        => $state,
                'created_at'   => '2026-05-28 00:00:00',
                'evaluated_at' => $state === 'evaluated' ? '2026-05-31 00:00:00' : null,
            ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Seed a prode_scores row with evaluation_method='result_only' (scored match).
     */
    private function seedScore( int $fechaId, int $userId, int $matchId, int $points = 1 ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => $matchId,
                'prediction_id'     => null,
                'points'            => $points,
                'evaluation_method' => 'result_only',
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    /**
     * Seed a prode_scores row with evaluation_method='no_match_score' (unscored match).
     */
    private function seedUnscoredMatch( int $fechaId, int $userId, int $matchId ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => $matchId,
                'prediction_id'     => null,
                'points'            => 0,
                'evaluation_method' => 'no_match_score',
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    private function countCacheRows( int $fechaId ): int {
        return $this->rankingRepo->countFechaCache( $fechaId );
    }

    /**
     * Registers a temporary listener on 'prode_ranking_cron_ran' (ADR-G8-1)
     * and fills $counters (by reference) with the three args it fired with.
     * Must be called BEFORE RankingCron::run().
     *
     * @param array{processed?: int, skipped_unscored?: int, skipped_empty?: int} $counters
     */
    private function captureCronCounters( array &$counters ): void {
        $counters = [ 'processed' => 0, 'skipped_unscored' => 0, 'skipped_empty' => 0 ];
        add_action(
            'prode_ranking_cron_ran',
            function ( int $processed, int $skippedUnscored, int $skippedEmpty ) use ( &$counters ): void {
                $counters = [
                    'processed'        => $processed,
                    'skipped_unscored' => $skippedUnscored,
                    'skipped_empty'    => $skippedEmpty,
                ];
            },
            10,
            3
        );
    }

    // -------------------------------------------------------------------------
    // CRN-01 — Single evaluated fecha, fully scored → 2 cache rows, hook fires
    // -------------------------------------------------------------------------

    public function test_single_evaluated_fecha_writes_cache_rows_and_fires_hook(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedUser( 2 );
        $this->seedScore( $fechaId, 1, 101, 3 );
        $this->seedScore( $fechaId, 2, 102, 1 );

        $counters = [];
        $this->captureCronCounters( $counters );

        RankingCron::run();

        $this->assertSame( 2, $this->countCacheRows( $fechaId ) );
        $this->assertGreaterThan( 0, did_action( 'prode_ranking_cron_ran' ) );
        $this->assertSame(
            [ 'processed' => 1, 'skipped_unscored' => 0, 'skipped_empty' => 0 ],
            $counters
        );
    }

    // -------------------------------------------------------------------------
    // CRN-02 — Skip locked fecha, process only evaluated
    // -------------------------------------------------------------------------

    public function test_skips_locked_fecha_and_processes_evaluated(): void {
        $lockedId    = $this->seedFecha( 'locked' );
        $evaluatedId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedScore( $lockedId, 1, 101, 1 );
        $this->seedScore( $evaluatedId, 1, 201, 3 );

        RankingCron::run();

        $this->assertSame( 0, $this->countCacheRows( $lockedId ), 'No cache for locked fecha.' );
        $this->assertSame( 1, $this->countCacheRows( $evaluatedId ), 'Cache row for evaluated fecha.' );
    }

    // -------------------------------------------------------------------------
    // CRN-03 — Idempotent re-run (no duplicates)
    // -------------------------------------------------------------------------

    public function test_idempotent_rerun_produces_no_duplicate_rows(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedUser( 2 );
        $this->seedScore( $fechaId, 1, 101, 3 );
        $this->seedScore( $fechaId, 2, 102, 1 );

        RankingCron::run();
        RankingCron::run();

        $this->assertSame( 2, $this->countCacheRows( $fechaId ), 'No duplicate rows after second run.' );
    }

    // -------------------------------------------------------------------------
    // CRN-04 — Evaluated fecha with unscored matches → skip cache, hook fires
    // -------------------------------------------------------------------------

    public function test_skips_evaluated_fecha_with_unscored_matches(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        // Two unscored matches (no_match_score) — countUnscoredMatches will return 2.
        $this->seedUnscoredMatch( $fechaId, 1, 101 );
        $this->seedUnscoredMatch( $fechaId, 1, 102 );

        $counters = [];
        $this->captureCronCounters( $counters );

        RankingCron::run();

        $this->assertSame( 0, $this->countCacheRows( $fechaId ), 'No cache for partially evaluated fecha.' );
        $this->assertGreaterThan( 0, did_action( 'prode_ranking_cron_ran' ) );
        $this->assertSame(
            [ 'processed' => 0, 'skipped_unscored' => 1, 'skipped_empty' => 0 ],
            $counters
        );
    }

    // -------------------------------------------------------------------------
    // CRN-07 — Counters (ADR-G8-1): processed / skipped_unscored / skipped_empty
    // distinguish all three loop outcomes in a single run.
    // -------------------------------------------------------------------------

    public function test_counters_distinguish_processed_skipped_unscored_and_skipped_empty(): void {
        $processedId = $this->seedFecha( 'evaluated' );
        $unscoredId  = $this->seedFecha( 'evaluated' );
        $emptyId     = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );

        $this->seedScore( $processedId, 1, 101, 3 );
        $this->seedUnscoredMatch( $unscoredId, 1, 201 );
        // $emptyId is seeded as 'evaluated' but has NO prode_scores rows at all:
        // countUnscoredMatches() returns 0 (nothing to count), yet
        // aggregateByFecha() also returns no rows — the "skipped_empty" branch.

        $counters = [];
        $this->captureCronCounters( $counters );

        RankingCron::run();

        $this->assertSame(
            [ 'processed' => 1, 'skipped_unscored' => 1, 'skipped_empty' => 1 ],
            $counters
        );
        $this->assertSame( 1, $this->countCacheRows( $processedId ) );
        $this->assertSame( 0, $this->countCacheRows( $unscoredId ) );
        $this->assertSame( 0, $this->countCacheRows( $emptyId ) );
    }

    // -------------------------------------------------------------------------
    // CRN-05 — Zero evaluated fechas → no exception, no cache, hook fires
    // -------------------------------------------------------------------------

    public function test_zero_evaluated_fechas_no_exception_hook_fires(): void {
        // Seed only an open fecha — no evaluated fechas.
        $this->seedFecha( 'open' );

        RankingCron::run();

        // No cache writes.
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_ranking_fecha_cache"
        );
        $this->assertSame( 0, $total );
        $this->assertGreaterThan( 0, did_action( 'prode_ranking_cron_ran' ) );
    }

    // -------------------------------------------------------------------------
    // CRN-06 — Two evaluated fechas both processed
    // -------------------------------------------------------------------------

    public function test_two_evaluated_fechas_both_processed(): void {
        $fechaId1 = $this->seedFecha( 'evaluated' );
        $fechaId2 = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedScore( $fechaId1, 1, 101, 3 );
        $this->seedScore( $fechaId2, 1, 201, 1 );

        RankingCron::run();

        $this->assertSame( 1, $this->countCacheRows( $fechaId1 ) );
        $this->assertSame( 1, $this->countCacheRows( $fechaId2 ) );
    }
}
