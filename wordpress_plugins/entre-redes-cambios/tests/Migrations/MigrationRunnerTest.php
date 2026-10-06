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
        global $wpdb, $wp_test_position_terms, $wp_test_position_terms_error;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_plaza" );
        $wp_test_position_terms       = [];
        $wp_test_position_terms_error = null;
        delete_option( 'entre_redes_cambios_arco_invariante_violaciones' );
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

        $this->assertTrue( $eventLog->has( 'arco.invariante_fallida' ) );

        $violacion = null;
        foreach ( $eventLog->all() as $event ) {
            if ( 'arco.invariante_fallida' === $event['evento'] ) {
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

        $this->assertFalse( $eventLog->has( 'arco.invariante_fallida' ) );
    }

    // -------------------------------------------------------------------------
    // THE VERDICT MUST BE PERSISTED, SO A LATER REQUEST CAN STILL RENDER IT
    // (0.1.14) — see checkEsArcoInvariant()'s own docblock for the production
    // incident this fixes: an admin_notices closure registered from INSIDE
    // run() only ever fires if that SAME one-time request also happens to be
    // an admin page render. These tests pin the fix by calling
    // renderEsArcoInvariantNotice() as its OWN, separate invocation — exactly
    // how Plugin::boot() wires it — never from inside the same run() call
    // that detected the violation.
    // -------------------------------------------------------------------------

    /**
     * *** THE EXACT BUG THIS TEST WOULD HAVE CAUGHT *** Before this fix, the
     * ONLY way to see this notice was for do_action('admin_notices') to fire
     * inside the SAME request as run() — which this test deliberately does
     * NOT do, proving the verdict survives independently of that one
     * request.
     */
    public function test_check_es_arco_invariant_persists_the_violation_for_a_later_render(): void {
        InitialSchema::up();
        delete_option( 'entre_redes_cambios_arco_invariante_violaciones' );

        // Team 100: zero es_arco plazas — a violation.
        $this->insertPreExistingPlaza( 359, 100, 700 );
        $this->insertPreExistingPlaza( 359, 100, 701 );

        MigrationRunner::run( new InMemoryEventLog() );

        // Simulate a LATER, unrelated admin request: nothing from the run()
        // call above is reused here beyond what it persisted.
        ob_start();
        MigrationRunner::renderEsArcoInvariantNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $output );
        $this->assertStringContainsString( 'team_id 100', $output );
    }

    public function test_render_es_arco_invariant_notice_is_a_no_op_when_nothing_is_persisted(): void {
        delete_option( 'entre_redes_cambios_arco_invariante_violaciones' );

        ob_start();
        MigrationRunner::renderEsArcoInvariantNotice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    /**
     * Repairing the data (here: forcing es_arco=1 directly, same technique
     * as the other invariant tests above) and re-running the migration must
     * clear the persisted verdict — otherwise an operator who already fixed
     * the problem would keep seeing a stale notice forever.
     */
    public function test_check_es_arco_invariant_clears_the_persisted_violation_once_repaired(): void {
        InitialSchema::up();
        delete_option( 'entre_redes_cambios_arco_invariante_violaciones' );

        $plazaId = $this->insertPreExistingPlaza( 359, 100, 700 );
        $this->insertPreExistingPlaza( 359, 100, 701 );

        MigrationRunner::run( new InMemoryEventLog() );

        ob_start();
        MigrationRunner::renderEsArcoInvariantNotice();
        $this->assertNotSame( '', ob_get_clean(), 'Precondition: the violation must be persisted before the repair.' );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cambios_plaza', [ 'es_arco' => 1 ], [ 'id' => $plazaId ] );

        MigrationRunner::run( new InMemoryEventLog() );

        ob_start();
        MigrationRunner::renderEsArcoInvariantNotice();
        $output = ob_get_clean();

        $this->assertSame( '', $output, 'A repaired invariant must clear the persisted verdict — no stale notice.' );
    }

    // -------------------------------------------------------------------------
    // A FAILED PosicionResolver RESOLUTION MUST ABORT THE BACKFILL ENTIRELY
    // (0.1.14) — see MigrationRunner::backfillEsArco()'s own docblock for the
    // production incident ("es_arco written as 0 for all 330 live plazas")
    // this discipline fixes.
    // -------------------------------------------------------------------------

    /**
     * *** THE EXACT BUG THIS TEST WOULD HAVE CAUGHT *** A plaza's `es_arco`
     * is forced to `1` directly (bypassing the derive-at-write-time path,
     * same technique `test_check_es_arco_invariant_reports_a_team_with_zero_and_a_team_with_two()`
     * above already uses) BEFORE the backfill runs with a forced position
     * resolution failure. The OLD behavior would have derived every titular
     * as `SIN_POSICION` (silently, from the WP_Error) and overwritten this
     * row back to `es_arco = 0` — flipping an already-correct flag. The FIX
     * must leave it untouched: the backfill must not even reach its write
     * loop when resolution fails.
     */
    public function test_backfill_writes_nothing_when_position_resolution_fails(): void {
        global $wp_test_position_terms_error;

        InitialSchema::up();

        $plazaId = $this->insertPreExistingPlaza( 359, 100, 700 );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cambios_plaza', [ 'es_arco' => 1 ], [ 'id' => $plazaId ] );

        $wp_test_position_terms_error = new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

        update_option( 'cambios_db_version', '0.0.1' );
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame(
            1,
            $this->esArcoDe( $plazaId ),
            'A row already flagged es_arco=1 must stay untouched when position resolution fails — '
                . 'the old bug would have overwritten it back to 0.'
        );
    }

    /**
     * The other half of the same harm: recording a half-done upgrade as
     * complete is just as dangerous as writing wrong data, because
     * `runIfOutdated()`'s version gate (see that method's own docblock) would
     * then skip the backfill forever on every later request, with no further
     * attempt to self-heal.
     */
    public function test_backfill_leaves_the_db_version_unbumped_when_position_resolution_fails(): void {
        global $wp_test_position_terms_error;

        InitialSchema::up();

        $this->insertPreExistingPlaza( 359, 100, 700 );
        $wp_test_position_terms_error = new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

        update_option( 'cambios_db_version', '0.0.1' );
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame(
            '0.0.1',
            get_option( 'cambios_db_version' ),
            'cambios_db_version must stay at the OLD value when the backfill could not complete, so the '
                . 'next request retries it instead of treating a half-done upgrade as finished.'
        );
    }

    public function test_backfill_failure_is_recorded_on_the_event_log(): void {
        global $wp_test_position_terms_error;

        InitialSchema::up();

        $this->insertPreExistingPlaza( 359, 100, 700 );
        $wp_test_position_terms_error = new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

        $eventLog = new InMemoryEventLog();
        update_option( 'cambios_db_version', '0.0.1' );
        MigrationRunner::run( $eventLog );

        $this->assertTrue(
            $eventLog->has( 'migracion.es_arco_backfill_fallida' ),
            'A failed backfill must be recorded on the EventLog, not merely returned as a bool nothing reads.'
        );
    }
}
