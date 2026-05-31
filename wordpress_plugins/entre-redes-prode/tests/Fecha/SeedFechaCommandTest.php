<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\SeedFechaCommand;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SeedFechaCommand::execute().
 *
 * WP_CLI is NOT available in the shim, so __invoke() is not tested directly.
 * All logic lives in the testable execute() method; __invoke() is a thin wrapper
 * that calls execute() and prints output via WP_CLI::success / WP_CLI::line.
 *
 * Idempotency is confirmed by calling execute() twice with the same stub resolver
 * and asserting no duplicate rows are created.
 */
class SeedFechaCommandTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
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

    private function makeCommand( callable $resolverFn ): SeedFechaCommand {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $lockComputer = new LockComputer();
        $repository   = new FechaRepository( $wpdb );

        return new SeedFechaCommand( $settings, $lockComputer, $repository, $resolverFn );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_execute_returns_fecha_id_and_match_count_when_resolver_has_result(): void {
        $cmd    = $this->makeCommand( fn() => $this->resolverResult() );
        $result = $cmd->execute();

        $this->assertIsInt( $result['fecha_id'] );
        $this->assertGreaterThan( 0, $result['fecha_id'] );
        $this->assertSame( 2, $result['match_count'] );
        $this->assertFalse( $result['skipped'] );
    }

    public function test_execute_creates_rows_in_db(): void {
        $cmd = $this->makeCommand( fn() => $this->resolverResult() );
        $cmd->execute();

        $this->assertSame( 1, $this->countFechas() );
        $this->assertSame( 2, $this->countFechaMatches() );
    }

    public function test_execute_returns_skipped_when_resolver_returns_null(): void {
        $cmd    = $this->makeCommand( fn() => null );
        $result = $cmd->execute();

        $this->assertTrue( $result['skipped'] );
        $this->assertSame( 0, $result['fecha_id'] );
        $this->assertSame( 0, $result['match_count'] );
    }

    public function test_execute_no_rows_inserted_when_resolver_returns_null(): void {
        $cmd = $this->makeCommand( fn() => null );
        $cmd->execute();

        $this->assertSame( 0, $this->countFechas() );
        $this->assertSame( 0, $this->countFechaMatches() );
    }

    public function test_execute_is_idempotent_second_call_returns_same_fecha_id(): void {
        $cmd = $this->makeCommand( fn() => $this->resolverResult() );

        $first  = $cmd->execute();
        $second = $cmd->execute();

        // Same fecha_id (existing row reused, not duplicated).
        $this->assertSame( $first['fecha_id'], $second['fecha_id'] );
    }

    public function test_execute_is_idempotent_no_duplicate_rows(): void {
        $cmd = $this->makeCommand( fn() => $this->resolverResult() );

        $cmd->execute();
        $cmd->execute();

        // Exactly 1 fecha and 2 matches after two runs.
        $this->assertSame( 1, $this->countFechas() );
        $this->assertSame( 2, $this->countFechaMatches() );
    }
}
