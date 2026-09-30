<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaImportCsvParser;
use EntreRedes\Cambios\Plazas\PlazaImporter;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for PlazaImporter against the in-memory SQLite shim —
 * real PlazaRepository + FechaRepository, plus ad hoc `wp_posts` /
 * `wp_term_relationships` / `wp_term_taxonomy` tables (WordPress core tables
 * this plugin's own test schema does not otherwise create — see
 * CandidatosResolverTest for the same pattern; this file adds a
 * `post_title` column CandidatosResolverTest's own ad hoc table does not
 * need, since name-based resolution is this class's whole job).
 */
class PlazaImporterTest extends TestCase {

    private const SEASON_ID = 359;
    private const FECHA_DESDE_ID = 1;
    private const NOW = '2026-03-01 10:00:00';

    private \wpdb $wpdb;
    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private PlazaImporter $importer;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $this->wpdb = $wpdb;
        $p          = $wpdb->prefix;

        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        // DROP + CREATE, never "IF NOT EXISTS": CandidatosResolverTest and
        // DictamenContextAssemblerTest each create their OWN ad hoc `posts`
        // table WITHOUT a `post_title` column, and share the same in-memory
        // SQLite connection across the whole PHPUnit process — whichever
        // test file's CREATE runs first would otherwise "win" for the rest
        // of the run, silently leaving this table without the one column
        // this class's whole job (name-based resolution) needs. Dropping and
        // recreating here, unconditionally, makes this schema win regardless
        // of test execution order; the two extra columns are harmless to
        // every OTHER test file's own narrower inserts.
        $wpdb->query( "DROP TABLE IF EXISTS {$p}posts" );
        $wpdb->query(
            "CREATE TABLE {$p}posts (
                ID INTEGER PRIMARY KEY,
                post_type TEXT,
                post_status TEXT,
                post_title TEXT
            )"
        );
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
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );

        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::SEASON_ID, 'term_id' => self::SEASON_ID, 'taxonomy' => 'sp_season' ] );

        $this->seedFecha( self::FECHA_DESDE_ID, self::SEASON_ID );

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->fechaRepository = new FechaRepository( $wpdb, $this->eventLog );
        $this->importer        = new PlazaImporter( $wpdb, $this->plazaRepository, $this->fechaRepository, $this->eventLog );
    }

    protected function tearDown(): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
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

    private function seedTeam( int $id, string $title ): void {
        $this->wpdb->insert( $this->wpdb->prefix . 'posts', [ 'ID' => $id, 'post_type' => 'sp_team', 'post_status' => 'publish', 'post_title' => $title ] );
    }

    private function seedPlayer( int $id, string $title, int $seasonId = self::SEASON_ID ): void {
        $p = $this->wpdb->prefix;
        $this->wpdb->insert( $p . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => $title ] );
        $this->wpdb->insert( $p . 'term_relationships', [ 'object_id' => $id, 'term_taxonomy_id' => $seasonId ] );
    }

    /** A player post that exists but is NOT registered in any season. */
    private function seedUnregisteredPlayer( int $id, string $title ): void {
        $this->wpdb->insert( $this->wpdb->prefix . 'posts', [ 'ID' => $id, 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => $title ] );
    }

    private function countPlazas(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}cambios_plaza" );
    }

    private function csv( string $body ): array {
        return PlazaImportCsvParser::parse( "equipo,titular,tipo,puntaje_techo\n" . $body );
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_happy_path_opens_the_right_plazas_with_titular_tipo_and_techo(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedPlayer( 111, 'Juan Perez' );
        $this->seedPlayer( 222, 'Martin Gomez' );

        // Mixes id- and title-based resolution for both columns, and both
        // decimal separators Puntaje::fromDecimal() must accept.
        $rows = $this->csv(
            "Boca Juniors,111,campo,3\n"
            . "100,Martin Gomez,suplente,\"2,5\"\n"
        );

        $plan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertCount( 2, $plan->rowsToOpen() );

        $opened = $this->importer->aplicar( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );

        $this->assertSame( 2, $opened );
        $this->assertSame( 2, $this->countPlazas() );

        $plazas = $this->plazaRepository->listPlazasByEquipo( self::SEASON_ID, 100 );
        $this->assertCount( 2, $plazas );

        $byTitular = [];
        foreach ( $plazas as $p ) {
            $byTitular[ (int) $p['titular_player_id'] ] = $p;
        }

        $this->assertSame( 'campo', (string) $byTitular[111]['tipo'] );
        $this->assertSame( 6, (int) $byTitular[111]['puntaje_techo'] ); // 3.0 -> 6 half-points
        $this->assertSame( 'suplente', (string) $byTitular[222]['tipo'] );
        $this->assertSame( 5, (int) $byTitular[222]['puntaje_techo'] ); // 2.5 -> 5 half-points

        $vigente = $this->plazaRepository->findOcupacionVigente( (int) $byTitular[111]['id'] );
        $this->assertNotNull( $vigente );
        $this->assertSame( 111, (int) $vigente['player_id'] );
        $this->assertSame( 1, (int) $vigente['es_genesis'] );
        $this->assertSame( self::FECHA_DESDE_ID, (int) $vigente['fecha_desde_id'] );
    }

    // -------------------------------------------------------------------------
    // Every hard error is detected, names its row, and ALL are reported together
    // -------------------------------------------------------------------------

    public function test_every_hard_error_is_detected_and_all_are_reported_together(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedTeam( 200, 'River Plate' );
        // Two teams sharing the same title -> ambiguous.
        $this->seedTeam( 201, 'Racing' );
        $this->seedTeam( 202, 'Racing' );

        $this->seedPlayer( 111, 'Juan Perez' );
        $this->seedPlayer( 222, 'Martin Gomez' );
        // Two players sharing the same title -> ambiguous.
        $this->seedPlayer( 301, 'Pedro Lopez' );
        $this->seedPlayer( 302, 'Pedro Lopez' );
        $this->seedUnregisteredPlayer( 400, 'Sin Inscribir' );

        $rows = $this->csv(
            // Fila 2: unknown team id.
            "99999,111,campo,3\n"
            // Fila 3: unknown team title.
            . "Equipo Fantasma,111,campo,3\n"
            // Fila 4: ambiguous team title.
            . "Racing,111,campo,3\n"
            // Fila 5: unknown player id.
            . "100,88888,campo,3\n"
            // Fila 6: unknown player title.
            . "100,Jugador Fantasma,campo,3\n"
            // Fila 7: ambiguous player title.
            . "100,Pedro Lopez,campo,3\n"
            // Fila 8: player not registered this season.
            . "100,400,campo,3\n"
            // Fila 9: invalid puntaje_techo.
            . "100,222,campo,2.3\n"
            // Fila 10: invalid tipo.
            . "100,222,banco,3\n"
            // Filas 11-12: same titular twice.
            . "100,111,campo,3\n"
            . "200,111,suplente,3\n"
        );

        $plan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $errors = implode( "\n", $plan->errors() );

        $this->assertStringContainsString( 'Fila 2', $errors );
        $this->assertStringContainsString( 'Fila 3', $errors );
        $this->assertStringContainsString( 'Fila 4', $errors );
        $this->assertStringContainsString( 'ambiguo', $errors );
        $this->assertStringContainsString( 'Fila 5', $errors );
        $this->assertStringContainsString( 'Fila 6', $errors );
        $this->assertStringContainsString( 'Fila 7', $errors );
        $this->assertStringContainsString( 'Fila 8', $errors );
        $this->assertStringContainsString( 'no esta registrado', $errors );
        $this->assertStringContainsString( 'Fila 9', $errors );
        $this->assertStringContainsString( 'Fila 10', $errors );
        $this->assertStringContainsString( 'tipo', $errors );
        $this->assertStringContainsString( '111', $errors );
        $this->assertStringContainsString( 'mas de una fila', $errors );
        $this->assertStringContainsString( '11', $errors ); // line 11
        $this->assertStringContainsString( '12', $errors ); // line 12

        // Nothing is queued, and refusing to apply is enforced, not just advised.
        $this->assertSame( [], $plan->rowsToOpen() );

        $this->expectException( \LogicException::class );
        $this->importer->aplicar( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
    }

    public function test_a_bad_row_anywhere_means_nothing_at_all_is_written(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedTeam( 200, 'River Plate' );
        $this->seedPlayer( 111, 'Juan Perez' );
        $this->seedPlayer( 222, 'Martin Gomez' );
        $this->seedPlayer( 333, 'Carlos Ruiz' );

        $rows = $this->csv(
            // Team 100 is entirely clean on its own...
            "100,111,campo,3\n"
            . "100,222,suplente,\"2,5\"\n"
            // ...but team 200's only row is broken.
            . "200,333,campo,no-es-un-numero\n"
        );

        $plan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertSame( [], $plan->rowsToOpen(), 'A bad row for ONE team must block the WHOLE import, including an otherwise-clean team.' );

        $this->assertSame( 0, $this->countPlazas(), 'Nothing must be written while the plan has errors.' );
    }

    public function test_team_already_has_plazas_is_a_hard_error_when_apply_would_add_more(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedPlayer( 111, 'Juan Perez' );
        $this->seedPlayer( 222, 'Martin Gomez' );

        // A plaza already exists for team 100 with a DIFFERENT titular than
        // what the CSV is about to bring.
        $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3 ), 'campo', self::FECHA_DESDE_ID, self::NOW );

        $rows = $this->csv( "100,222,campo,3\n" );

        $plan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertTrue( $plan->hasErrors() );
        $this->assertStringContainsString( 'ya tiene', implode( "\n", $plan->errors() ) );
        $this->assertSame( [], $plan->rowsToOpen() );
        $this->assertSame( 1, $this->countPlazas(), 'The pre-existing plaza must be untouched.' );
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_a_second_apply_run_opens_nothing(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedPlayer( 111, 'Juan Perez' );
        $this->seedPlayer( 222, 'Martin Gomez' );

        $rows = $this->csv(
            "100,111,campo,3\n"
            . "100,222,suplente,\"2,5\"\n"
        );

        $firstPlan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );
        $this->assertFalse( $firstPlan->hasErrors() );
        $firstOpened = $this->importer->aplicar( $firstPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );
        $this->assertSame( 2, $firstOpened );

        $secondPlan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $secondPlan->hasErrors(), implode( "\n", $secondPlan->errors() ) );
        $this->assertSame( [], $secondPlan->rowsToOpen(), 'A team already fully imported must queue nothing the second time.' );

        $summaries = $secondPlan->teamSummaries();
        $this->assertCount( 1, $summaries );
        $this->assertSame( 'ya_importado', $summaries[0]['estado'] );

        $secondOpened = $this->importer->aplicar( $secondPlan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );

        $this->assertSame( 0, $secondOpened );
        $this->assertSame( 2, $this->countPlazas(), 'No duplicate plazas must be created by re-running --apply.' );
    }

    public function test_a_half_finished_import_completes_without_duplicating_the_done_team(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedTeam( 200, 'River Plate' );
        $this->seedPlayer( 111, 'Juan Perez' );
        $this->seedPlayer( 222, 'Martin Gomez' );

        // Team 100 was already imported by a prior run; team 200 was not.
        $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3 ), 'campo', self::FECHA_DESDE_ID, self::NOW );

        $rows = $this->csv(
            "100,111,campo,3\n"
            . "200,222,campo,3\n"
        );

        $plan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertCount( 1, $plan->rowsToOpen(), 'Only the team not yet imported should be queued.' );
        $this->assertSame( 200, $plan->rowsToOpen()[0]['team_id'] );

        $opened = $this->importer->aplicar( $plan, self::SEASON_ID, self::FECHA_DESDE_ID, self::NOW );

        $this->assertSame( 1, $opened );
        $this->assertSame( 2, $this->countPlazas() );
    }

    // -------------------------------------------------------------------------
    // 9+2 — warning, never an error
    // -------------------------------------------------------------------------

    public function test_a_9_plus_2_mismatch_warns_without_blocking_the_import(): void {
        $this->seedTeam( 100, 'Boca Juniors' );
        $this->seedPlayer( 111, 'Jugador Uno' );
        $this->seedPlayer( 222, 'Jugador Dos' );
        $this->seedPlayer( 333, 'Jugador Tres' );

        // Only 1 campo + 2 suplente — a legitimate, if incomplete, roster.
        $rows = $this->csv(
            "100,111,campo,3\n"
            . "100,222,suplente,3\n"
            . "100,333,suplente,3\n"
        );

        $plan = $this->importer->planificar( $rows, self::SEASON_ID, self::FECHA_DESDE_ID );

        $this->assertFalse( $plan->hasErrors(), implode( "\n", $plan->errors() ) );
        $this->assertNotEmpty( $plan->warnings() );
        $this->assertStringContainsString( 'Boca Juniors', $plan->warnings()[0] );
        $this->assertStringContainsString( '9 campo', $plan->warnings()[0] );
        $this->assertCount( 3, $plan->rowsToOpen(), 'A 9+2 mismatch is a warning, not a reason to refuse the rows.' );
    }
}
