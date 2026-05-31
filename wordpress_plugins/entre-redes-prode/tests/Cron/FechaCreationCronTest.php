<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Cron;

use EntreRedes\Prode\Cron\FechaCreationCron;
use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Integration-ish tests for FechaCreationCron.
 *
 * Uses the SQLite shim for FechaRepository persistence and a stub FechaResolver
 * dispatcher injected via the non-static execute() method (ADR-G0-7 seam).
 *
 * The static run() entrypoint is not tested directly (it requires a real WP
 * runtime to instantiate real collaborators). Instead, the logic is extracted
 * into execute() which accepts all collaborators as parameters — fully testable.
 */
class FechaCreationCronTest extends TestCase {

    private FechaRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        // Reset action counter for cron hook.
        $GLOBALS['_prode_test_actions']['prode_fecha_creation_cron_ran'] = 0;

        $this->repo = new FechaRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up(); // restore seeds
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a stub resolver result with 2 matches for 2026-05-30.
     */
    private function resolverResult(): array {
        return [
            'play_date'        => '2026-05-30',
            'earliest_kickoff' => '2026-05-30 13:45',
            'matches'          => [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'Home A', 'away_team' => 'Away A' ],
                [ 'match_id' => 11, 'kickoff' => '2026-05-30 15:10', 'home_team' => 'Home B', 'away_team' => 'Away B' ],
            ],
        ];
    }

    private function countFechas(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_fechas" );
    }

    private function countFechaMatches(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_fecha_matches" );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_first_run_creates_fecha_and_match_rows(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $result       = $this->resolverResult();
        $stubResolver = fn() => $result;

        $cron = new FechaCreationCron();
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );

        $this->assertSame( 1, $this->countFechas() );
        $this->assertSame( 2, $this->countFechaMatches() );
    }

    public function test_two_runs_are_idempotent(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $result       = $this->resolverResult();
        $stubResolver = fn() => $result;

        $cron = new FechaCreationCron();
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );

        // Idempotent — same fecha, same match count.
        $this->assertSame( 1, $this->countFechas() );
        $this->assertSame( 2, $this->countFechaMatches() );
    }

    public function test_null_resolver_result_is_noop(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $stubResolver = fn() => null; // no upcoming matches

        $cron = new FechaCreationCron();
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );

        $this->assertSame( 0, $this->countFechas() );
        $this->assertSame( 0, $this->countFechaMatches() );
    }

    public function test_do_action_fired_when_resolver_returns_result(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $result       = $this->resolverResult();
        $stubResolver = fn() => $result;

        $cron = new FechaCreationCron();
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );

        $this->assertGreaterThan( 0, did_action( 'prode_fecha_creation_cron_ran' ) );
    }

    public function test_do_action_fired_when_resolver_returns_null(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $stubResolver = fn() => null;

        $cron = new FechaCreationCron();
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );

        $this->assertGreaterThan( 0, did_action( 'prode_fecha_creation_cron_ran' ) );
    }

    public function test_team_names_not_written_to_db(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $result       = $this->resolverResult();
        $stubResolver = fn() => $result;

        $cron = new FechaCreationCron();
        $cron->execute( $settings, $lockComputer, $this->repo, $stubResolver );

        $row = $wpdb->get_row(
            "SELECT fecha_id, match_id, match_kickoff FROM {$wpdb->prefix}prode_fecha_matches LIMIT 1",
            ARRAY_A
        );

        $this->assertNotNull( $row );
        $this->assertArrayNotHasKey( 'home_team', $row );
        $this->assertArrayNotHasKey( 'away_team', $row );
    }
}
