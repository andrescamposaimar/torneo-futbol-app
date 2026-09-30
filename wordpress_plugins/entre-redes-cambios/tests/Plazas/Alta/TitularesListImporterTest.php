<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Alta;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\Alta\TitularesListImporter;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TitularesListImporter against the in-memory SQLite
 * shim — real PlazaRepository, CapitanRepository and FechaRepository, plus
 * ad hoc `wp_term_relationships` / `wp_term_taxonomy` tables (same pattern
 * `Plazas\Eleccion\EleccionImporterTest` used — see its own removed test
 * file in version control history).
 *
 * Every fixture id is INVENTED — no personal data anywhere in this file.
 */
class TitularesListImporterTest extends TestCase {

    private const SEASON_ID      = 359;
    private const FECHA_DESDE_ID = 1;
    private const NOW            = '2026-03-01 10:00:00';

    private \wpdb $wpdb;
    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private CapitanRepository $capitanRepository;
    private FechaRepository $fechaRepository;
    private TitularesListImporter $importer;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $this->wpdb = $wpdb;
        $p          = $wpdb->prefix;

        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}cambios_capitan" );

        wp_test_create_posts_table( $wpdb );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_relationships (
                object_id INTEGER,
                term_taxonomy_id INTEGER
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER,
                taxonomy TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );

        // The `sp_season` term_taxonomy row itself — seedPlayer() only adds
        // the OBJECT's term_relationships row; this is what makes
        // term_taxonomy_id = SEASON_ID actually MEAN "the sp_season taxonomy,
        // term SEASON_ID" for loadPlayersRegisteredInSeason()'s join.
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::SEASON_ID, 'term_id' => self::SEASON_ID, 'taxonomy' => 'sp_season' ] );

        $this->seedFecha( self::FECHA_DESDE_ID, self::SEASON_ID );

        $this->eventLog          = new InMemoryEventLog();
        $this->plazaRepository   = new PlazaRepository( $wpdb, $this->eventLog );
        $this->capitanRepository = new CapitanRepository( $wpdb, $this->eventLog );
        $this->fechaRepository   = new FechaRepository( $wpdb, $this->eventLog );
        $this->importer          = new TitularesListImporter( $wpdb, $this->plazaRepository, $this->capitanRepository, $this->fechaRepository, $this->eventLog );
    }

    protected function tearDown(): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}cambios_capitan" );
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFecha( int $fechaId, int $seasonId ): void {
        $this->wpdb->insert(
            $this->wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => '2026-03-01',
                'play_date_original' => '2026-03-01',
                'created_at'         => self::NOW,
                'updated_at'         => self::NOW,
            ]
        );
    }

    private function seedTeam( int $id, string $title = 'Equipo', string $status = 'publish' ): void {
        $this->wpdb->insert( $this->wpdb->prefix . 'posts', [ 'ID' => $id, 'post_type' => 'sp_team', 'post_status' => $status, 'post_title' => $title ] );
    }

    /** A season-registered, published sp_player. */
    private function seedPlayer( int $id, string $title = 'Jugador', int $seasonId = self::SEASON_ID ): void {
        $p = $this->wpdb->prefix;
        $this->wpdb->insert( $p . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => $title ] );
        $this->wpdb->insert( $p . 'term_relationships', [ 'object_id' => $id, 'term_taxonomy_id' => $seasonId ] );
    }

    /** An sp_player post that exists but is NOT registered in any season. */
    private function seedUnregisteredPlayer( int $id, string $title = 'Jugador sin registrar' ): void {
        $this->wpdb->insert( $this->wpdb->prefix . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => $title ] );
    }

    /** An sp_player post that exists, IS registered in the season, but sits at a non-publish status (e.g. 'trash'). */
    private function seedPlayerWithStatus( int $id, string $status, string $title = 'Jugador', int $seasonId = self::SEASON_ID ): void {
        $p = $this->wpdb->prefix;
        $this->wpdb->insert( $p . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => $status, 'post_title' => $title ] );
        $this->wpdb->insert( $p . 'term_relationships', [ 'object_id' => $id, 'term_taxonomy_id' => $seasonId ] );
    }

    private function countPlazas(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}cambios_plaza" );
    }

    /**
     * Builds a valid 11-row team: $teamId, one es_capitan row, 10 field
     * rows — player ids are $teamId * 100 + vuelta (1..11), all invented.
     *
     * @return array<int, array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}>
     */
    private function elevenRows( int $teamId, string $equipo = 'Equipo' ): array {
        $rows = [];
        for ( $i = 1; $i <= 11; $i++ ) {
            $rows[] = [
                'line'              => $i,
                'team_id'           => $teamId,
                'equipo'            => $equipo,
                'titular_player_id' => $teamId * 100 + $i,
                'puntaje_raw'       => '3.0',
                'es_capitan'        => 1 === $i,
            ];
        }
        return $rows;
    }

    private function seedRosterFor( array $rows ): void {
        $seeded = [];
        foreach ( $rows as $row ) {
            $id = $row['titular_player_id'];
            if ( isset( $seeded[ $id ] ) ) {
                continue; // A duplicate-player fixture reuses the same id on purpose.
            }
            $seeded[ $id ] = true;
            $this->seedPlayer( $id );
        }
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_happy_path_opens_plazas_with_the_right_titular_and_ceiling(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertCount( 11, $plan->rowsToOpen() );
        $this->assertCount( 1, $plan->capitanes() );
        $this->assertSame( 900101, $plan->capitanes()[0]['player_id'] );

        $opened = $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 11, $opened );
        $this->assertSame( 11, $this->countPlazas() );

        $plaza = $this->plazaRepository->findPlaza(
            (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->wpdb->prefix}cambios_plaza WHERE titular_player_id = %d",
                    900101
                )
            )
        );
        $this->assertNotNull( $plaza );
        $this->assertSame( 6, (int) $plaza['puntaje_techo'] ); // 3.0 => 6 half-points.

        $designated = $this->importer->aplicarCapitanes( $plan, self::SEASON_ID, self::NOW );
        $this->assertSame( 1, $designated );
        $this->assertTrue( $this->capitanRepository->isCapitanVigente( self::SEASON_ID, 9001, 900101 ) );
    }

    // -------------------------------------------------------------------------
    // Hard errors — every one named, all reported together
    // -------------------------------------------------------------------------

    public function test_a_nonexistent_team_id_is_a_hard_error(): void {
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );
        // Team 9001 itself is never seeded as an sp_team.

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertStringContainsString( '9001', implode( "\n", $plan->errors() ) );
        $this->assertStringContainsString( 'no existe', implode( "\n", $plan->errors() ) );
    }

    public function test_a_nonexistent_titular_player_id_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        // Only 10 of the 11 players are seeded — 900101 is left unresolved.
        foreach ( $rows as $row ) {
            if ( 900101 === $row['titular_player_id'] ) {
                continue;
            }
            $this->seedPlayer( $row['titular_player_id'] );
        }

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $errors = implode( "\n", $plan->errors() );
        $this->assertStringContainsString( '900101', $errors );
        $this->assertStringContainsString( 'no existe', $errors );
    }

    public function test_a_player_not_registered_in_the_season_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        foreach ( $rows as $row ) {
            if ( 900101 === $row['titular_player_id'] ) {
                $this->seedUnregisteredPlayer( $row['titular_player_id'] );
                continue;
            }
            $this->seedPlayer( $row['titular_player_id'] );
        }

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $errors = implode( "\n", $plan->errors() );
        $this->assertStringContainsString( '900101', $errors );
        $this->assertStringContainsString( 'no esta registrado', $errors );
    }

    /**
     * A player that IS registered in the season but sits in the trash is a
     * different problem than "does not exist" — and must be reported as
     * one: the operator's fix is to restore the post, not to hunt for a
     * typo in an id that is already correct. See
     * TitularesListImporter::loadPlayerStatuses()'s own class docblock.
     */
    public function test_a_trashed_player_is_a_hard_error_naming_its_status(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        foreach ( $rows as $row ) {
            if ( 900101 === $row['titular_player_id'] ) {
                $this->seedPlayerWithStatus( $row['titular_player_id'], 'trash' );
                continue;
            }
            $this->seedPlayer( $row['titular_player_id'] );
        }

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $errors = implode( "\n", $plan->errors() );
        $this->assertStringContainsString( '900101', $errors );
        $this->assertStringContainsString( 'trash', $errors );
        $this->assertStringNotContainsString( 'no existe como sp_player', $errors, 'A trashed post is NOT the same problem as a nonexistent one.' );
        $this->assertSame( [], $plan->rowsToOpen() );
        $this->assertSame( 0, $this->countPlazas() );
    }

    /**
     * Same distinction as the trashed-player case, for teams: a draft team
     * is a real post that is simply not published yet, never a typo.
     */
    public function test_a_draft_team_is_a_hard_error_naming_its_status(): void {
        $this->seedTeam( 9001, 'Equipo', 'draft' );
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $errors = implode( "\n", $plan->errors() );
        $this->assertStringContainsString( '9001', $errors );
        $this->assertStringContainsString( 'draft', $errors );
        $this->assertStringNotContainsString( 'no existe como sp_team', $errors, 'A draft post is NOT the same problem as a nonexistent one.' );
        $this->assertSame( [], $plan->rowsToOpen() );
        $this->assertSame( 0, $this->countPlazas() );
    }

    public function test_a_puntaje_outside_the_9_valid_values_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $rows[0]['puntaje_raw'] = '2.3'; // Not one of the 9 valid puntajes.
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
    }

    public function test_the_same_player_twice_in_the_file_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $rows[1]['titular_player_id'] = $rows[0]['titular_player_id']; // Duplicate.
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertStringContainsString( 'aparece', implode( "\n", $plan->errors() ) );
    }

    public function test_more_than_one_es_capitan_per_team_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows              = $this->elevenRows( 9001 );
        $rows[1]['es_capitan'] = true; // Now two captains.
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertStringContainsString( 'es_capitan=1', implode( "\n", $plan->errors() ) );
    }

    public function test_a_team_with_no_es_capitan_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows                  = $this->elevenRows( 9001 );
        $rows[0]['es_capitan'] = false; // No captain at all now.
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertStringContainsString( 'ninguna fila tiene es_capitan=1', implode( "\n", $plan->errors() ) );
    }

    public function test_every_hard_error_is_reported_together_not_just_the_first(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        // Player at index 1 does not exist at all; puntaje at index 2 is invalid.
        $rows[2]['puntaje_raw'] = '2.3';
        foreach ( $rows as $i => $row ) {
            if ( 1 === $i ) {
                continue; // Leave this player entirely unseeded.
            }
            $this->seedPlayer( $row['titular_player_id'] );
        }

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertGreaterThanOrEqual( 2, count( $plan->errors() ), implode( "\n", $plan->errors() ) );
    }

    // -------------------------------------------------------------------------
    // A bad row anywhere leaves the table empty
    // -------------------------------------------------------------------------

    public function test_a_bad_row_anywhere_means_nothing_at_all_is_written(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $rows[5]['puntaje_raw'] = '2.3'; // One bad row, buried in the middle.
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertSame( [], $plan->rowsToOpen(), 'One bad row must block the WHOLE team, including its otherwise-clean rows.' );
        $this->assertSame( [], $plan->capitanes() );

        $this->expectException( \LogicException::class );
        $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
    }

    public function test_apply_refuses_and_the_table_stays_empty_when_the_plan_has_errors(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $rows[5]['puntaje_raw'] = '2.3';
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        try {
            $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
            $this->fail( 'Expected LogicException.' );
        } catch ( \LogicException $e ) {
            // Expected.
        }

        $this->assertSame( 0, $this->countPlazas() );
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_a_second_apply_run_opens_nothing(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );

        $firstPlan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $firstPlan->hasErrors(), implode( "\n", $firstPlan->errors() ) );
        $firstOpened = $this->importer->aplicarPlazas( $firstPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 11, $firstOpened );

        $secondPlan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $secondPlan->hasErrors(), implode( "\n", $secondPlan->errors() ) );
        $this->assertSame( [], $secondPlan->rowsToOpen() );
        $this->assertSame( 'ya_importado', $secondPlan->teamSummaries()[0]['estado'] );

        $secondOpened = $this->importer->aplicarPlazas( $secondPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 0, $secondOpened );
        $this->assertSame( 11, $this->countPlazas() );
    }

    public function test_an_existing_conflicting_roster_is_a_hard_error(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );

        $firstPlan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->importer->aplicarPlazas( $firstPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );

        // Reimport with a different puntaje for the same titular — conflicts
        // with what is already open, must never be silently resolved.
        $rows[1]['puntaje_raw'] = '4.5';

        $secondPlan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $secondPlan->hasErrors() );
        $this->assertStringContainsString( 'conflicto', implode( "\n", array_column( $secondPlan->teamSummaries(), 'estado' ) ) );
    }

    // -------------------------------------------------------------------------
    // Captaincy runs independently
    // -------------------------------------------------------------------------

    public function test_captaincy_can_be_applied_without_ever_applying_plazas(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );

        $this->assertSame( 0, $this->countPlazas() );

        $designated = $this->importer->aplicarCapitanes( $plan, self::SEASON_ID, self::NOW );

        $this->assertSame( 1, $designated );
        $this->assertTrue( $this->capitanRepository->isCapitanVigente( self::SEASON_ID, 9001, 900101 ) );
        $this->assertSame( 0, $this->countPlazas(), 'Applying captaincy must never open a plaza.' );
    }

    public function test_plazas_can_be_applied_without_ever_applying_captaincy(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $opened = $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );

        $this->assertSame( 11, $opened );
        $this->assertFalse( $this->capitanRepository->isCapitanVigente( self::SEASON_ID, 9001, 900101 ) );
    }

    // -------------------------------------------------------------------------
    // Row count other than 11 — warning, not an error
    // -------------------------------------------------------------------------

    public function test_a_team_with_fewer_than_11_rows_only_warns(): void {
        $this->seedTeam( 9001 );
        $rows = $this->elevenRows( 9001 );
        array_pop( $rows ); // 10 rows now.
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertNotEmpty( $plan->warnings() );
        $this->assertStringContainsString( '10 fila', implode( "\n", $plan->warnings() ) );
        $this->assertCount( 10, $plan->rowsToOpen() );

        $opened = $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 10, $opened );
    }

    public function test_parser_level_errors_are_forwarded_and_block_the_whole_import(): void {
        $this->seedTeam( 9001 );
        $rows          = $this->elevenRows( 9001 );
        $parserErrors  = [ "Fila 3: 'team_id' invalido." ];
        $this->seedRosterFor( $rows );

        $plan = $this->importer->planificar( $rows, $parserErrors, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertSame( [], $plan->rowsToOpen() );
    }
}
