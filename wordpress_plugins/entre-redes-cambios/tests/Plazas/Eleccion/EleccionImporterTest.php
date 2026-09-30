<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\Eleccion\EleccionImporter;
use EntreRedes\Cambios\Plazas\Eleccion\EleccionSheetParser;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for EleccionImporter against the in-memory SQLite shim —
 * real PlazaRepository, CapitanRepository and FechaRepository, plus ad hoc
 * `wp_term_relationships` / `wp_term_taxonomy` / `wp_postmeta` tables (same
 * pattern the CSV importer this class replaces used — see its own removed
 * test file in version control history — plus `wp_postmeta` for
 * `sp_current_team`, which this importer additionally needs).
 *
 * Every fixture name is INVENTED but shaped like the real cases the task
 * brief names: a stray space before a comma, a compound surname, a team-name
 * alias, an unresolved titular with and without an override, an ambiguous
 * tie, a team with fewer than 11 rows, a bad row anywhere leaving the table
 * empty, a second --apply opening nothing, and the captaincy step running
 * independently of the plaza step.
 */
class EleccionImporterTest extends TestCase {

    private const SEASON_ID       = 359;
    private const FECHA_DESDE_ID  = 1;
    private const NOW             = '2026-03-01 10:00:00';
    private const TERM_ALTA       = 155;
    private const TERM_BAJA       = 156;
    private const TERM_POSITION_TAXONOMY = 'sp_position';

    private \wpdb $wpdb;
    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private CapitanRepository $capitanRepository;
    private FechaRepository $fechaRepository;
    private EleccionImporter $importer;

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
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY,
                post_id INTEGER,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        // The `sp_season` term_taxonomy row itself — seedPlayer() only adds
        // the OBJECT's term_relationships row; this is what makes
        // term_taxonomy_id = SEASON_ID actually MEAN "the sp_season taxonomy,
        // term SEASON_ID" for loadJugadoresRegistrados()'s join.
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::SEASON_ID, 'term_id' => self::SEASON_ID, 'taxonomy' => 'sp_season' ] );

        $this->seedFecha( self::FECHA_DESDE_ID, self::SEASON_ID );

        $this->eventLog         = new InMemoryEventLog();
        $this->plazaRepository  = new PlazaRepository( $wpdb, $this->eventLog );
        $this->capitanRepository = new CapitanRepository( $wpdb, $this->eventLog );
        $this->fechaRepository  = new FechaRepository( $wpdb, $this->eventLog );
        $this->importer         = new EleccionImporter( $wpdb, $this->plazaRepository, $this->capitanRepository, $this->fechaRepository, $this->eventLog );
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
        $wpdb->query( "DELETE FROM {$p}postmeta" );
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

    private function seedTeam( int $id, string $title ): void {
        $this->wpdb->insert( $this->wpdb->prefix . 'posts', [ 'ID' => $id, 'post_type' => 'sp_team', 'post_status' => 'publish', 'post_title' => $title ] );
    }

    /** A season-registered, published sp_player, on $teamId's WordPress roster (sp_current_team). */
    private function seedPlayer( int $id, string $title, int $teamId, int $seasonId = self::SEASON_ID ): void {
        $p = $this->wpdb->prefix;
        $this->wpdb->insert( $p . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => $title ] );
        $this->wpdb->insert( $p . 'term_relationships', [ 'object_id' => $id, 'term_taxonomy_id' => $seasonId ] );
        $this->wpdb->insert( $p . 'postmeta', [ 'post_id' => $id, 'meta_key' => 'sp_current_team', 'meta_value' => (string) $teamId ] );
    }

    private function seedUnregisteredPlayer( int $id, string $title, int $teamId ): void {
        $p = $this->wpdb->prefix;
        $this->wpdb->insert( $p . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => $title ] );
        $this->wpdb->insert( $p . 'postmeta', [ 'post_id' => $id, 'meta_key' => 'sp_current_team', 'meta_value' => (string) $teamId ] );
    }

    private function flagPosition( int $playerId, int $termId ): void {
        $p = $this->wpdb->prefix;
        $ttid = $termId + 100000; // Distinct term_taxonomy_id per fixture, never collides with season ids used in these tests.
        $this->wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => $ttid, 'term_id' => $termId, 'taxonomy' => self::TERM_POSITION_TAXONOMY ] );
        $this->wpdb->insert( $p . 'term_relationships', [ 'object_id' => $playerId, 'term_taxonomy_id' => $ttid ] );
    }

    private function countPlazas(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}cambios_plaza" );
    }

    /**
     * Builds a valid 11-row "x Equipo" team block: CAP + 10 field titulares.
     * `$nombres` maps vuelta ('CAP','1'..'10') to the (still un-normalized)
     * Excel name for that row; missing vueltas are filled with an invented
     * default so a test only needs to specify what it cares about.
     *
     * @param array<string, string> $nombres
     * @return array{equipo: string, line: int, titulares: array<string, string>}
     */
    private function equipoTeam( string $equipo, array $nombres = [] ): array {
        $titulares = [];
        foreach ( array_merge( [ 'CAP' ], range( 1, 10 ) ) as $vuelta ) {
            $vuelta               = (string) $vuelta;
            $titulares[ $vuelta ] = $nombres[ $vuelta ] ?? "Jugador {$equipo}, Nombre{$vuelta}";
        }
        return [ 'equipo' => $equipo, 'line' => 2, 'titulares' => $titulares ];
    }

    /**
     * @param array<string, string> $puntajePorNombreNormalizado
     * @return array<string, string>
     */
    private function puntajesParaEquipo( array $titulares, string $puntajeDefault = '3' ): array {
        $puntajes = [];
        foreach ( $titulares as $nombre ) {
            $puntajes[ \EntreRedes\Cambios\Plazas\Eleccion\TextNormalizer::normalize( $nombre ) ] = $puntajeDefault;
        }
        return $puntajes;
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_happy_path_resolves_plazas_and_captain(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Perez, Juan' ] );
        $this->seedPlayer( 111, 'Perez, Juan', 100 );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }

        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertCount( 11, $plan->rowsToOpen() );
        $this->assertCount( 1, $plan->capitanes() );
        $this->assertSame( 111, $plan->capitanes()[0]['player_id'] );

        $opened = $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 11, $opened );
        $this->assertSame( 11, $this->countPlazas() );

        $designated = $this->importer->aplicarCapitanes( $plan, self::SEASON_ID, self::NOW );
        $this->assertSame( 1, $designated );
        $this->assertTrue( $this->capitanRepository->isCapitanVigente( self::SEASON_ID, 100, 111 ) );
    }

    public function test_team_alias_resolves_to_the_wordpress_team(): void {
        $this->seedTeam( 15796, 'Costa De Marfil' );

        $team = $this->equipoTeam( "Cote D'Ivoire" );
        foreach ( $team['titulares'] as $vuelta => $nombre ) {
            $this->seedPlayer( 300 + (int) ( 'CAP' === $vuelta ? 0 : $vuelta ), $nombre, 15796 );
        }
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertSame( 15796, $plan->teamSummaries()[0]['team_id'] );
    }

    public function test_a_stray_space_and_a_compound_surname_still_resolve(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca', [
            'CAP' => 'Sinclair , Juan Martin',
            '1'   => 'Moralejo Rivera, Ezequiel',
        ] );

        $this->seedPlayer( 111, 'Sinclair, Juan Martin', 100 );
        $this->seedPlayer( 112, 'Moralejo, Ezequiel', 100 );
        for ( $i = 2; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }

        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $ids = array_map( static fn ( array $r ): int => $r['titular_player_id'], $plan->rowsToOpen() );
        $this->assertContains( 111, $ids );
        $this->assertContains( 112, $ids );
    }

    // -------------------------------------------------------------------------
    // Unresolved titulares — with and without an override
    // -------------------------------------------------------------------------

    public function test_an_unresolved_titular_with_no_override_blocks_the_whole_import(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Chiesa, Gustavo Alejandro' ] );
        // No player named "Chiesa, Gustavo Alejandro" (nor anything overlapping) exists on the roster.
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertStringContainsString( 'Chiesa', implode( "\n", $plan->errors() ) );
        $this->assertSame( [], $plan->rowsToOpen() );
        $this->assertSame( [], $plan->capitanes() );

        $this->expectException( \LogicException::class );
        $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
    }

    public function test_an_override_resolves_a_titular_the_matcher_could_not(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Chiesa, Gustavo Alejandro' ] );
        $this->seedPlayer( 999, 'Un Nombre Completamente Distinto', 100 ); // The real WP identity, unmatched by name.
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes  = $this->puntajesParaEquipo( $team['titulares'] );
        $overrides = [ 'CHIESA, GUSTAVO ALEJANDRO' => 999 ];

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], $overrides, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $ids = array_map( static fn ( array $r ): int => $r['titular_player_id'], $plan->rowsToOpen() );
        $this->assertContains( 999, $ids );
    }

    public function test_an_override_pointing_at_a_nonexistent_player_is_an_error(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Chiesa, Gustavo Alejandro' ] );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes  = $this->puntajesParaEquipo( $team['titulares'] );
        $overrides = [ 'CHIESA, GUSTAVO ALEJANDRO' => 88888 ];

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], $overrides, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
    }

    // -------------------------------------------------------------------------
    // Ambiguous tie
    // -------------------------------------------------------------------------

    public function test_an_ambiguous_titular_is_a_hard_error_naming_every_candidate(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Gonzalez Martinez, Juan' ] );
        $this->seedPlayer( 501, 'Gonzalez, Juan Carlos', 100 );
        $this->seedPlayer( 502, 'Martinez, Juan Pablo', 100 );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $errors = implode( "\n", $plan->errors() );
        $this->assertStringContainsString( 'ambiguo', $errors );
        $this->assertStringContainsString( '501', $errors );
        $this->assertStringContainsString( '502', $errors );
    }

    // -------------------------------------------------------------------------
    // A team with fewer than 11 rows (parser-level error, forwarded)
    // -------------------------------------------------------------------------

    public function test_a_team_with_fewer_than_11_rows_blocks_the_whole_import(): void {
        $this->seedTeam( 100, 'Boca' );
        $this->seedPlayer( 111, 'Alguien, Nombre', 100 );

        $equipoRows = [
            [ 'Vuelta', 'Equipo', 'id', 'Nombre', 'Celular', 'mail', 'Fijo' ],
            [ 'CAP', 'Boca', '1', 'Alguien, Nombre', '', '', '' ],
        ];
        $parsed = EleccionSheetParser::parseEquipoSheet( $equipoRows );

        $this->assertNotEmpty( $parsed['errors'] );

        $plan = $this->importer->planificar( $parsed['teams'], $parsed['errors'], [], [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertSame( [], $plan->rowsToOpen() );

        $this->expectException( \LogicException::class );
        $this->importer->aplicarPlazas( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
    }

    // -------------------------------------------------------------------------
    // A bad row anywhere leaves the table empty
    // -------------------------------------------------------------------------

    public function test_a_bad_puntaje_anywhere_means_nothing_at_all_is_written(): void {
        $this->seedTeam( 100, 'Boca' );

        $team = $this->equipoTeam( 'Boca' );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $this->seedPlayer( 300, $team['titulares']['CAP'], 100 );

        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );
        // Corrupt the CAP's own puntaje.
        $puntajes[ \EntreRedes\Cambios\Plazas\Eleccion\TextNormalizer::normalize( $team['titulares']['CAP'] ) ] = 'no-es-un-numero';

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertSame( [], $plan->rowsToOpen(), 'One bad titular must block the WHOLE team, including its otherwise-clean rows.' );
        $this->assertSame( 0, $this->countPlazas() );
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_a_second_apply_run_opens_nothing(): void {
        $this->seedTeam( 100, 'Boca' );
        $team = $this->equipoTeam( 'Boca' );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $this->seedPlayer( 300, $team['titulares']['CAP'], 100 );
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $firstPlan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $firstPlan->hasErrors(), implode( "\n", $firstPlan->errors() ) );
        $firstOpened = $this->importer->aplicarPlazas( $firstPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 11, $firstOpened );

        $secondPlan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $secondPlan->hasErrors(), implode( "\n", $secondPlan->errors() ) );
        $this->assertSame( [], $secondPlan->rowsToOpen() );
        $this->assertSame( 'ya_importado', $secondPlan->teamSummaries()[0]['estado'] );

        $secondOpened = $this->importer->aplicarPlazas( $secondPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 0, $secondOpened );
        $this->assertSame( 11, $this->countPlazas() );
    }

    // -------------------------------------------------------------------------
    // Captaincy runs independently
    // -------------------------------------------------------------------------

    public function test_captaincy_can_be_applied_without_ever_applying_plazas(): void {
        $this->seedTeam( 100, 'Boca' );
        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Perez, Juan' ] );
        $this->seedPlayer( 111, 'Perez, Juan', 100 );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );

        // Plazas are never applied in this test.
        $this->assertSame( 0, $this->countPlazas() );

        $designated = $this->importer->aplicarCapitanes( $plan, self::SEASON_ID, self::NOW );

        $this->assertSame( 1, $designated );
        $this->assertTrue( $this->capitanRepository->isCapitanVigente( self::SEASON_ID, 100, 111 ) );
        $this->assertSame( 0, $this->countPlazas(), 'Applying captaincy must never open a plaza.' );
    }

    // -------------------------------------------------------------------------
    // Reemplazo report (read-only)
    // -------------------------------------------------------------------------

    public function test_reemplazo_report_flags_a_team_whose_baja_and_alta_counts_differ(): void {
        $this->seedTeam( 100, 'Boca' );
        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Perez, Juan' ] );
        $this->seedPlayer( 111, 'Perez, Juan', 100 );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        // The captain (an official titular) is flagged reemplazo_baja...
        $this->flagPosition( 111, self::TERM_BAJA );
        // ...but nobody extra on the roster is flagged reemplazo_alta, and one
        // extra player (not a titular) sits on the roster unflagged either way.
        $this->seedPlayer( 900, 'Suplente Extra, Nombre', 100 );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );

        $reporte = $this->importer->reportarReemplazos( $plan );

        $this->assertCount( 1, $reporte->porEquipo() );
        $this->assertSame( 1, $reporte->porEquipo()[0]['titulares_con_baja'] );
        $this->assertSame( 0, $reporte->porEquipo()[0]['altas_no_titulares'] );
        $this->assertTrue( $reporte->porEquipo()[0]['difieren'] );

        $extraIds = array_map( static fn ( array $e ): int => $e['player_id'], $reporte->extras() );
        $this->assertContains( 900, $extraIds );
    }

    public function test_reemplazo_report_omits_a_team_whose_titulares_did_not_resolve(): void {
        $this->seedTeam( 100, 'Boca' );
        $team = $this->equipoTeam( 'Boca', [ 'CAP' => 'Chiesa, Gustavo Alejandro' ] );
        for ( $i = 1; $i <= 10; $i++ ) {
            $this->seedPlayer( 200 + $i, "Jugador Boca, Nombre{$i}", 100 );
        }
        $puntajes = $this->puntajesParaEquipo( $team['titulares'] );

        $plan = $this->importer->planificar( [ $team ], [], $puntajes, [], [], self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertTrue( $plan->hasErrors() );

        $reporte = $this->importer->reportarReemplazos( $plan );

        $this->assertSame( [], $reporte->porEquipo() );
        $this->assertNotEmpty( $reporte->omitidos() );
    }
}
