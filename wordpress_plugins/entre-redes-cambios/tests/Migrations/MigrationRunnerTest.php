<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Migrations;

use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Migrations\MigrationRunner;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * MigrationRunner::run() against the in-memory SQLite shim.
 *
 * The InnoDB storage-engine check queries `information_schema.TABLES`, which
 * does not exist under SQLite — tests/wp-shim.php's `$wpdb->get_var()`
 * catches the resulting PDOException and returns null (see that shim's
 * docblock). These tests assert the tolerant side of that check: it must
 * never fail activation, and must never report a false "not InnoDB", when
 * the query itself could not run.
 */
class MigrationRunnerTest extends TestCase {

    public function test_run_does_not_throw_and_does_not_report_a_false_non_innodb_when_the_engine_query_is_unavailable(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::run( $eventLog );

        $this->assertFalse(
            $eventLog->has( 'motor.no_innodb' ),
            'The SQLite shim has no information_schema — the check must treat an unavailable query as '
                . '"could not check", never as "found a non-InnoDB table".'
        );
    }

    public function test_run_updates_the_stored_db_version_option(): void {
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CAMBIOS_VERSION, get_option( 'cambios_db_version' ) );
    }

    /**
     * The deployment reality this guards: a new zip is uploaded over an
     * ALREADY ACTIVE plugin, so `register_activation_hook` — run()'s only
     * other caller — never fires. Without an upgrade-time run, a release that
     * adds a column would leave that column uncreated on the live site.
     */
    public function test_run_if_outdated_migrates_when_the_stored_version_is_older(): void {
        update_option( 'cambios_db_version', '0.0.1' );

        MigrationRunner::runIfOutdated( new InMemoryEventLog() );

        $this->assertSame(
            ENTRE_REDES_CAMBIOS_VERSION,
            get_option( 'cambios_db_version' ),
            'An older stored schema version must trigger the migration on a plain plugin upgrade.'
        );
    }

    public function test_run_if_outdated_migrates_when_no_version_was_ever_stored(): void {
        update_option( 'cambios_db_version', false );

        MigrationRunner::runIfOutdated( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CAMBIOS_VERSION, get_option( 'cambios_db_version' ) );
    }

    public function test_run_if_outdated_is_a_no_op_once_the_stored_version_is_current(): void {
        update_option( 'cambios_db_version', ENTRE_REDES_CAMBIOS_VERSION );

        $eventLog = new InMemoryEventLog();
        MigrationRunner::runIfOutdated( $eventLog );

        // Nothing to assert about the schema (dbDelta is idempotent anyway);
        // what matters is that the current version is left untouched and the
        // call is cheap enough to sit on every request.
        $this->assertSame( ENTRE_REDES_CAMBIOS_VERSION, get_option( 'cambios_db_version' ) );
    }

    // -------------------------------------------------------------------------
    // es_arco backfill + invariant check (0.1.13)
    // -------------------------------------------------------------------------

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_plaza" );
        $wp_test_position_terms = [];
    }

    /**
     * @return int The inserted plaza's id.
     */
    private function insertPreExistingPlaza( int $seasonId, int $teamId, int $titularPlayerId ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        // Deliberately omits `es_arco` from the INSERT — exactly what a
        // plaza row created before this column existed looks like: the
        // column falls back to its schema DEFAULT (0), the "no team has a
        // goal plaza" state backfillEsArco() exists to fix.
        $wpdb->insert( $p . 'cambios_plaza', [
            'season_id'         => $seasonId,
            'team_id'           => $teamId,
            'titular_player_id' => $titularPlayerId,
            'puntaje_techo'     => 6,
            'created_at'        => '2026-01-01 00:00:00',
            'closed_at'         => null,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function esArcoDe( int $plazaId ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT es_arco FROM {$p}cambios_plaza WHERE id = %d", $plazaId )
        );
    }

    public function test_run_backfills_es_arco_marking_exactly_the_plazas_whose_titular_is_an_arquero(): void {
        global $wp_test_position_terms;

        InitialSchema::up();

        // Team 100: titular 700 is the goalkeeper (term 3), 701 and 702 are
        // field players (no seeded position at all).
        $plazaArquero = $this->insertPreExistingPlaza( 359, 100, 700 );
        $plazaCampoA  = $this->insertPreExistingPlaza( 359, 100, 701 );
        $plazaCampoB  = $this->insertPreExistingPlaza( 359, 100, 702 );

        $wp_test_position_terms = [ 700 => [ 3 ] ];

        update_option( 'cambios_db_version', '0.0.1' );
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( 1, $this->esArcoDe( $plazaArquero ), 'The plaza whose titular is the Arquero must be backfilled to es_arco=1.' );
        $this->assertSame( 0, $this->esArcoDe( $plazaCampoA ), 'A field plaza must stay es_arco=0.' );
        $this->assertSame( 0, $this->esArcoDe( $plazaCampoB ), 'A field plaza must stay es_arco=0.' );
    }

    public function test_backfill_is_idempotent_running_it_twice_changes_nothing(): void {
        global $wp_test_position_terms;

        InitialSchema::up();

        $plazaArquero = $this->insertPreExistingPlaza( 359, 100, 700 );
        $plazaCampo   = $this->insertPreExistingPlaza( 359, 100, 701 );
        $wp_test_position_terms = [ 700 => [ 3 ] ];

        update_option( 'cambios_db_version', '0.0.1' );
        MigrationRunner::run( new InMemoryEventLog() );

        $firstArquero = $this->esArcoDe( $plazaArquero );
        $firstCampo   = $this->esArcoDe( $plazaCampo );

        // Force the one-time task to run a second time.
        update_option( 'cambios_db_version', '0.0.1' );
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( 1, $firstArquero );
        $this->assertSame( 0, $firstCampo );
        $this->assertSame( $firstArquero, $this->esArcoDe( $plazaArquero ), 'Re-running the backfill must not change an already-correct value.' );
        $this->assertSame( $firstCampo, $this->esArcoDe( $plazaCampo ), 'Re-running the backfill must not change an already-correct value.' );
    }

    public function test_check_es_arco_invariant_reports_a_team_with_zero_and_a_team_with_two(): void {
        InitialSchema::up();

        // Team 100: zero es_arco plazas (both field players, position unseeded).
        $this->insertPreExistingPlaza( 359, 100, 700 );
        $this->insertPreExistingPlaza( 359, 100, 701 );

        // Team 200: two es_arco plazas, written directly (bypassing the
        // derive-at-write-time path) to pin the CHECK itself, independent of
        // how es_arco got that way.
        global $wpdb;
        $p = $wpdb->prefix;
        $plazaA = $this->insertPreExistingPlaza( 359, 200, 800 );
        $plazaB = $this->insertPreExistingPlaza( 359, 200, 801 );
        $wpdb->update( $p . 'cambios_plaza', [ 'es_arco' => 1 ], [ 'id' => $plazaA ] );
        $wpdb->update( $p . 'cambios_plaza', [ 'es_arco' => 1 ], [ 'id' => $plazaB ] );

        $eventLog = new InMemoryEventLog();
        MigrationRunner::run( $eventLog );

        $this->assertTrue( $eventLog->has( 'arco.invariante_violada' ) );

        $violacion = null;
        foreach ( $eventLog->all() as $event ) {
            if ( 'arco.invariante_violada' === $event['evento'] ) {
                $violacion = $event['contexto']['equipos'];
            }
        }
        $this->assertNotNull( $violacion );

        $porTeam = [];
        foreach ( $violacion as $v ) {
            $porTeam[ $v['team_id'] ] = $v['es_arco_count'];
        }

        $this->assertSame( 0, $porTeam[100] ?? null, 'Team 100 (zero es_arco plazas) must be reported.' );
        $this->assertSame( 2, $porTeam[200] ?? null, 'Team 200 (two es_arco plazas) must be reported.' );
    }

    public function test_check_es_arco_invariant_does_not_report_a_correctly_flagged_team(): void {
        global $wp_test_position_terms;

        InitialSchema::up();

        $this->insertPreExistingPlaza( 359, 100, 700 );
        $this->insertPreExistingPlaza( 359, 100, 701 );
        $wp_test_position_terms = [ 700 => [ 3 ] ];

        $eventLog = new InMemoryEventLog();
        update_option( 'cambios_db_version', '0.0.1' ); // force the backfill to actually run for this fixture.
        MigrationRunner::run( $eventLog );

        $this->assertFalse( $eventLog->has( 'arco.invariante_violada' ) );
    }
}
