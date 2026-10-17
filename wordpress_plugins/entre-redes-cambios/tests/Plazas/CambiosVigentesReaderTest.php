<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CambiosVigentesReader;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the reader shared by the admin "Cambios vigentes"
 * table and the captains' `GET /cambios/vigentes`. Real repositories: the
 * mapping (titular = baja, current occupant = alta) and the situación are
 * derived facts that a stub could only echo back.
 */
class CambiosVigentesReaderTest extends TestCase {

    private const SEASON_ID = 359;

    private PlazaRepository $plazaRepository;
    private InMemoryEventLog $eventLog;
    private CambiosVigentesReader $reader;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb, $wp_test_post_titles;
        $p = $wpdb->prefix;

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );

        foreach ( [ 'postmeta', 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wp_test_post_titles = [
            100 => 'SUIZA',
            200 => 'ARGENTINA',
            777 => 'Perez, Titular',
            888 => 'Gomez, Suplente',
            999 => 'ARIEL GAUTERO',
            555 => 'Lopez, Otro',
        ];

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->reader          = $this->construirReader( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ 'postmeta', 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        unset( $GLOBALS['wp_test_post_titles'] );
    }

    private function construirReader( \wpdb $wpdb, ?FechaRepository $fechaRepository = null ): CambiosVigentesReader {
        return new CambiosVigentesReader(
            $this->plazaRepository,
            new SolicitudesAbiertasReader( $wpdb, $this->eventLog ),
            $fechaRepository ?? new FechaRepository( $wpdb, new InMemoryEventLog() ),
            new Settings( $wpdb, $this->eventLog ),
            $this->eventLog,
            new JugadorMetricasReader( $wpdb, $this->eventLog )
        );
    }

    public function test_the_titular_is_the_baja_and_the_current_occupant_is_the_alta(): void {
        $this->seedFechas( 4, 1 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );
        $this->putPuntaje( 777, '3.5' );
        $this->putPuntaje( 888, '2' );

        $rows = $this->reader->listarPublico( self::SEASON_ID );

        $this->assertCount( 1, $rows );
        $row = $rows[0];
        $this->assertSame( 100, $row['team_id'] );
        $this->assertSame( 'SUIZA', $row['team_nombre'] );
        $this->assertSame( $plazaId, $row['plaza_id'] );
        $this->assertFalse( $row['es_arco'] );
        $this->assertSame(
            [ 'player_id' => 777, 'nombre' => 'Perez, Titular', 'puntaje' => 3.5, 'sin_calificar' => false ],
            $row['baja']
        );
        $this->assertSame(
            [ 'player_id' => 888, 'nombre' => 'Gomez, Suplente', 'puntaje' => 2.0, 'sin_calificar' => false ],
            $row['alta']
        );
    }

    public function test_in_a_chain_the_titular_stays_the_baja_and_the_last_occupant_is_the_alta(): void {
        $this->seedFechas( 4, 1 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 999, 3, 'reemplazada', '2026-03-17 00:00:00' );

        $rows = $this->reader->listarPublico( self::SEASON_ID );

        $this->assertCount( 1, $rows, 'A chain is ONE live substitution, not one row per link.' );
        $this->assertSame( 777, $rows[0]['baja']['player_id'] );
        $this->assertSame( 999, $rows[0]['alta']['player_id'] );
        $this->assertSame( 'ARIEL GAUTERO', $rows[0]['alta']['nombre'], 'Names are returned as stored; flipping is the client\'s job.' );
        $this->assertSame( 3, $rows[0]['desde']['fecha_id'], 'Desde is the fecha the CURRENT occupant entered.' );
    }

    public function test_an_arco_reassignment_row_is_flagged_es_arco(): void {
        $this->seedFechas( 4, 1 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cambios_plaza', [ 'es_arco' => 1 ], [ 'id' => $plazaId ] );
        $this->plazaRepository->succeedOcupacion( $plazaId, 555, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $rows = $this->reader->listarPublico( self::SEASON_ID );

        $this->assertCount( 1, $rows );
        $this->assertTrue( $rows[0]['es_arco'] );
        $this->assertSame( 777, $rows[0]['baja']['player_id'] );
        $this->assertSame( 555, $rows[0]['alta']['player_id'] );
    }

    public function test_a_stored_puntaje_of_zero_is_sin_calificar_never_zero(): void {
        $this->seedFechas( 4, 1 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );
        $this->putPuntaje( 777, '0' );
        // 888 has no metrics at all.

        $row = $this->reader->listarPublico( self::SEASON_ID )[0];

        $this->assertNull( $row['baja']['puntaje'] );
        $this->assertTrue( $row['baja']['sin_calificar'] );
        $this->assertNull( $row['alta']['puntaje'] );
        $this->assertTrue( $row['alta']['sin_calificar'] );
    }

    public function test_desde_is_the_fecha_label_not_a_raw_id(): void {
        $this->seedFechas( 4, 1 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $row = $this->reader->listarPublico( self::SEASON_ID )[0];

        $this->assertSame( [ 'fecha_id' => 2, 'label' => 'Apertura Fecha 2' ], $row['desde'] );
    }

    public function test_situacion_says_how_many_fechas_are_missing_using_the_admin_wording(): void {
        $this->seedFechas( 4, 2 ); // fechas 1-2 jugada, 3-4 programada
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $row = $this->reader->listarPublico( self::SEASON_ID )[0];

        $this->assertSame( 'Faltan 2 fechas para poder volver.', $row['situacion'] );
        $this->assertSame( CambiosVigentesReader::SITUACION_FALTAN_FECHAS, $row['situacion_tipo'] );
        $this->assertSame( 2, $row['fechas_restantes'] );
    }

    public function test_situacion_says_the_titular_can_return_when_the_minimum_is_met(): void {
        $this->seedFechas( 4, 4 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $row = $this->reader->listarPublico( self::SEASON_ID )[0];

        $this->assertSame( 'Renovado 1 fecha más.', $row['situacion'] );
        $this->assertSame( CambiosVigentesReader::SITUACION_PUEDE_VOLVER, $row['situacion_tipo'] );
        $this->assertNull( $row['fechas_restantes'] );
    }

    public function test_the_public_list_never_reveals_an_open_solicitud_but_the_admin_still_sees_it(): void {
        $this->seedFechas( 4, 4 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        global $wpdb;
        $settings  = new Settings( $wpdb, $this->eventLog );
        $pipeline  = new DictamenPipeline(
            new DictamenContextAssembler( $this->plazaRepository, new FechaRepository( $wpdb, new InMemoryEventLog() ), $settings, $wpdb, $this->eventLog ),
            $this->eventLog
        );
        $solicitudRepository = new SolicitudRepository( $wpdb, $this->plazaRepository, $pipeline, $this->eventLog );
        $solicitudId         = $solicitudRepository->crear(
            SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 4, time() ),
            777,
            Dictamen::from( [] ),
            '2026-04-01 10:00:00'
        );

        $row = $this->reader->listarPublico( self::SEASON_ID )[0];

        $this->assertSame( 'Renovado 1 fecha más.', $row['situacion'] );
        $this->assertSame( CambiosVigentesReader::SITUACION_PUEDE_VOLVER, $row['situacion_tipo'] );
        $this->assertStringNotContainsStringIgnoringCase( 'solicitud', json_encode( $row ) );
        $this->assertStringNotContainsStringIgnoringCase( 'pendiente', json_encode( $row ) );

        $admin = CambiosVigentesReader::situacion( $this->reader->listar( self::SEASON_ID )[0] );
        $this->assertSame( 'Solicitud #' . $solicitudId . ' abierta (Pendiente)', $admin['texto'] );
    }

    public function test_the_public_list_fails_instead_of_inventing_sin_calificar_without_metrics(): void {
        $this->seedFechas( 4, 1 );
        $plazaId = $this->abrirPlaza( 100, 777 );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        global $wpdb;
        $sinMetricas = new CambiosVigentesReader(
            $this->plazaRepository,
            new SolicitudesAbiertasReader( $wpdb, $this->eventLog ),
            new FechaRepository( $wpdb, new InMemoryEventLog() ),
            new Settings( $wpdb, $this->eventLog ),
            $this->eventLog
        );

        $this->expectException( \LogicException::class );
        $sinMetricas->listarPublico( self::SEASON_ID );
    }

    public function test_rows_are_sorted_by_team_name_then_plaza_and_a_titular_in_place_is_excluded(): void {
        $this->seedFechas( 4, 1 );
        $suiza1 = $this->abrirPlaza( 100, 777 );
        $suiza2 = $this->abrirPlaza( 100, 888 );
        $arg    = $this->abrirPlaza( 200, 555 );
        $this->abrirPlaza( 200, 999 ); // titular in place: not a vigente

        $this->plazaRepository->succeedOcupacion( $suiza2, 999, 2, 'reemplazada', '2026-03-10 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $suiza1, 555, 2, 'reemplazada', '2026-03-10 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $arg, 777, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $rows = $this->reader->listarPublico( self::SEASON_ID );

        $this->assertSame(
            [ [ 'ARGENTINA', $arg ], [ 'SUIZA', $suiza1 ], [ 'SUIZA', $suiza2 ] ],
            array_map( static fn ( array $r ): array => [ $r['team_nombre'], $r['plaza_id'] ], $rows )
        );
    }

    public function test_without_vigentes_the_list_is_empty(): void {
        $this->seedFechas( 2, 1 );
        $this->abrirPlaza( 100, 777 );

        $this->assertSame( [], $this->reader->listarPublico( self::SEASON_ID ) );
    }

    private function abrirPlaza( int $teamId, int $titularId ): int {
        return $this->plazaRepository->openPlaza( self::SEASON_ID, $teamId, $titularId, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
    }

    private function putPuntaje( int $playerId, string $puntaje ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( [ 'puntaje' => $puntaje ] ),
            ]
        );
    }

    /** Seeds fechas 1..$total, the first $jugadas of them `jugada`. */
    private function seedFechas( int $total, int $jugadas ): void {
        global $wpdb;

        for ( $id = 1; $id <= $total; $id++ ) {
            $playDate = sprintf( '2026-03-%02d', $id );

            $wpdb->insert(
                $wpdb->prefix . 'cambios_fecha',
                [
                    'id'                 => $id,
                    'season_id'          => self::SEASON_ID,
                    'orden'              => $id,
                    'torneo_liga_ids'    => '1',
                    'torneo_label'       => 'Apertura',
                    'numero_en_torneo'   => $id,
                    'play_date'          => $playDate,
                    'play_date_original' => $playDate,
                    'estado'             => $id <= $jugadas ? 'jugada' : 'programada',
                    'created_at'         => '2026-01-01 00:00:00',
                    'updated_at'         => '2026-01-01 00:00:00',
                ]
            );
        }
    }
}
