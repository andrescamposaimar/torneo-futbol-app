<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Capitania;

use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for CapitanRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap (see InitialSchema's class docblock): the dbDelta
 * shim drops every KEY/INDEX line, and the "at most one vigent captain per
 * team" rule was never a UNIQUE KEY to begin with (see
 * sqlCambiosCapitan()'s docblock for why). These tests assert that PROPERTY
 * directly — never two vigent rows for the same (season_id, team_id) —
 * rather than relying on any DB constraint.
 */
class CapitanRepositoryTest extends TestCase {

    private CapitanRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_capitan" );

        $this->repo = new CapitanRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_capitan" );
    }

    private function countVigentesFor( int $seasonId, int $teamId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_capitan
                  WHERE season_id = %d AND team_id = %d AND revocado_at IS NULL",
                $seasonId,
                $teamId
            )
        );
    }

    private function countRowsFor( int $seasonId, int $teamId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_capitan
                  WHERE season_id = %d AND team_id = %d",
                $seasonId,
                $teamId
            )
        );
    }

    // -------------------------------------------------------------------------
    // designar — creates
    // -------------------------------------------------------------------------

    public function test_designar_creates_a_vigent_row(): void {
        $id = $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertGreaterThan( 0, $id );
        $this->assertTrue( $this->repo->esCapitanVigente( 359, 100, 777 ) );
        $this->assertSame( 1, $this->countVigentesFor( 359, 100 ) );
    }

    // -------------------------------------------------------------------------
    // designar — changing captain revokes the previous one
    // -------------------------------------------------------------------------

    public function test_designar_a_different_player_revokes_the_previous_captain(): void {
        $firstId  = $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $secondId = $this->repo->designar( 359, 100, 888, 7, '2026-09-27 10:00:00' );

        $this->assertNotSame( $firstId, $secondId );
        $this->assertFalse( $this->repo->esCapitanVigente( 359, 100, 777 ) );
        $this->assertTrue( $this->repo->esCapitanVigente( 359, 100, 888 ) );

        // THE property test: exactly one vigent row, never zero, never two.
        $this->assertSame( 1, $this->countVigentesFor( 359, 100 ) );
        $this->assertSame( 2, $this->countRowsFor( 359, 100 ), 'The revoked row must remain as history.' );
    }

    // -------------------------------------------------------------------------
    // designar — idempotent on the SAME player
    // -------------------------------------------------------------------------

    public function test_designar_the_same_incumbent_player_is_idempotent(): void {
        $firstId  = $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $secondId = $this->repo->designar( 359, 100, 777, 7, '2026-09-27 10:00:00' );

        $this->assertSame( $firstId, $secondId, 'Re-designating the incumbent must not create a new row.' );
        $this->assertSame( 1, $this->countRowsFor( 359, 100 ), 'No revocation and no new row for the incumbent.' );
        $this->assertTrue( $this->repo->esCapitanVigente( 359, 100, 777 ) );
    }

    // -------------------------------------------------------------------------
    // revocar
    // -------------------------------------------------------------------------

    public function test_revocar_clears_the_vigent_captain(): void {
        $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $result = $this->repo->revocar( 359, 100, '2026-09-27 10:00:00' );

        $this->assertTrue( $result );
        $this->assertNull( $this->repo->capitanVigente( 359, 100 ) );
        $this->assertFalse( $this->repo->esCapitanVigente( 359, 100, 777 ) );
    }

    public function test_revocar_returns_false_when_there_is_nothing_to_revoke(): void {
        $this->assertFalse( $this->repo->revocar( 359, 100, '2026-09-27 10:00:00' ) );
    }

    // -------------------------------------------------------------------------
    // capitanVigente / esCapitanVigente
    // -------------------------------------------------------------------------

    public function test_capitan_vigente_returns_null_when_no_captain_was_ever_designated(): void {
        $this->assertNull( $this->repo->capitanVigente( 359, 100 ) );
    }

    public function test_capitan_vigente_returns_null_after_a_revoke(): void {
        $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $this->repo->revocar( 359, 100, '2026-09-27 10:00:00' );

        $this->assertNull( $this->repo->capitanVigente( 359, 100 ) );
    }

    public function test_es_capitan_vigente_is_false_for_a_different_team(): void {
        $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertFalse( $this->repo->esCapitanVigente( 359, 200, 777 ) );
    }

    // -------------------------------------------------------------------------
    // equiposDeCapitan
    // -------------------------------------------------------------------------

    public function test_equipos_de_capitan_returns_the_team_this_player_captains(): void {
        $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertSame( [ 100 ], $this->repo->equiposDeCapitan( 359, 777 ) );
    }

    public function test_equipos_de_capitan_is_empty_when_the_player_captains_nothing(): void {
        $this->assertSame( [], $this->repo->equiposDeCapitan( 359, 777 ) );
    }

    public function test_equipos_de_capitan_excludes_a_revoked_team(): void {
        $this->repo->designar( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $this->repo->revocar( 359, 100, '2026-09-27 10:00:00' );

        $this->assertSame( [], $this->repo->equiposDeCapitan( 359, 777 ) );
    }

    // -------------------------------------------------------------------------
    // THE property: never two vigent rows for the same (season, team), across
    // a longer sequence of designations.
    // -------------------------------------------------------------------------

    public function test_never_two_vigent_rows_for_the_same_pair_across_several_designations(): void {
        $this->repo->designar( 359, 100, 1, null, '2026-09-01 10:00:00' );
        $this->repo->designar( 359, 100, 2, null, '2026-09-08 10:00:00' );
        $this->repo->designar( 359, 100, 2, null, '2026-09-09 10:00:00' ); // idempotent no-op
        $this->repo->designar( 359, 100, 3, null, '2026-09-15 10:00:00' );

        $this->assertSame( 1, $this->countVigentesFor( 359, 100 ) );
        $this->assertTrue( $this->repo->esCapitanVigente( 359, 100, 3 ) );

        global $wpdb;
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_capitan WHERE season_id = %d AND team_id = %d",
                359,
                100
            )
        );
        $this->assertSame( 3, $total, 'Every designation must be preserved as history.' );
    }

    public function test_designations_are_scoped_per_season(): void {
        $this->repo->designar( 359, 100, 777, null, '2026-09-01 10:00:00' );
        $this->repo->designar( 360, 100, 888, null, '2026-09-01 10:00:00' );

        $this->assertTrue( $this->repo->esCapitanVigente( 359, 100, 777 ) );
        $this->assertTrue( $this->repo->esCapitanVigente( 360, 100, 888 ) );
        $this->assertFalse( $this->repo->esCapitanVigente( 359, 100, 888 ) );
    }
}
