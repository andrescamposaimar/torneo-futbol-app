<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\Exception\TransicionInvalidaException;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for SolicitudRepository — built against the REAL
 * DictamenPipeline (real DictamenContextAssembler, real PlazaRepository,
 * real FechaRepository/Settings), the same style as DictamenPipelineTest,
 * so `publicarLote()`'s re-evaluation is exercised against real dictamen
 * rules, not a stub.
 */
class SolicitudRepositoryTest extends TestCase {

    private const SEASON_ID = 359;

    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private InMemoryEventLog $eventLog;
    private DictamenPipeline $pipeline;
    private SolicitudRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->fechaRepository = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $this->settings        = new Settings( $wpdb );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog
        );

        $this->pipeline = new DictamenPipeline( $assembler, $this->eventLog );
        $this->repo     = new SolicitudRepository( $wpdb, $this->plazaRepository, $this->pipeline, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFecha( int $fechaId, int $seasonId, string $playDate, string $estado = 'programada' ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => $estado,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    private function seedPuntaje( int $playerId, float $puntaje ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( [ 'puntaje' => (string) $puntaje ] ),
            ]
        );
    }

    /**
     * An epoch guaranteed inside every plazo window for $playDate — both
     * `apertura_solicitudes` and `apertura_solicitudes` are the shared start
     * point for a `regreso` and a `sustitucion` alike (see
     * Dictamen\Reglas\SolicitudEnPlazo's docblock), so one hour after it is
     * always safely "en plazo" regardless of $tipo.
     */
    private function instanteEnPlazo( string $playDate ): int {
        $plazos = PlazosCalculator::computeUtc( $playDate, $this->settings->plazosOffsets(), $this->settings->timezone() );

        return ( new \DateTimeImmutable( $plazos['apertura_solicitudes'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 3600;
    }

    /**
     * @param \wpdb $real A live wpdb sharing THIS test's SQLite connection.
     */
    private function wpdbThatFailsOnNthOcupacionInsert( \wpdb $real, int $failOnCall ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $failOnCall ) extends \wpdb {
            private int $calls = 0;
            private int $failOnCall;

            public function __construct( \PDO $pdo, string $prefix, int $failOnCall ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix     = $prefix;
                $this->failOnCall = $failOnCall;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                if ( str_ends_with( $table, 'cambios_ocupacion' ) ) {
                    $this->calls++;

                    if ( $this->calls === $this->failOnCall ) {
                        $this->last_error = 'simulated insert failure for test (ocupacion insert #' . $this->calls . ')';
                        return false;
                    }
                }

                return parent::insert( $table, $data, $format );
            }
        };
    }

    // -------------------------------------------------------------------------
    // crear()
    // -------------------------------------------------------------------------

    public function test_crear_guarda_el_dictamen_como_snapshot_favorable(): void {
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() );

        $id = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->assertGreaterThan( 0, $id );

        $row = $this->repo->findSolicitud( $id );
        $this->assertNotNull( $row );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
        $this->assertSame( '2026-05-27 10:00:00', $row['solicitada_at'] );
        $this->assertSame( 777, (int) $row['solicitada_por'] );
        $this->assertNull( $row['dictamen_aplicado'] );

        $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );
        $this->assertTrue( $snapshot->procede() );
        $this->assertSame( [], $snapshot->motivoCodigos() );

        $this->assertTrue( $this->eventLog->has( 'solicitud.creada' ) );
    }

    public function test_crear_guarda_el_dictamen_como_snapshot_con_motivos(): void {
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() );
        $dictamen  = Dictamen::from( [ new Motivo( 'fuera_de_plazo', 'fuera de plazo' ) ] );

        $id  = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $row = $this->repo->findSolicitud( $id );

        $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );
        $this->assertFalse( $snapshot->procede() );
        $this->assertSame( [ 'fuera_de_plazo' ], $snapshot->motivoCodigos() );
    }

    public function test_find_solicitud_returns_null_when_missing(): void {
        $this->assertNull( $this->repo->findSolicitud( 999999 ) );
    }

    public function test_list_pendientes_and_aprobadas_filter_by_season_and_estado(): void {
        $now = '2026-05-27 10:00:00';

        $idA           = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), $now );
        $idB           = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 2, 889, 5, time() ), 777, Dictamen::from( [] ), $now );
        $idOtraSeason  = $this->repo->crear( SolicitudDeCambio::sustitucion( 999, 100, 3, 890, 5, time() ), 777, Dictamen::from( [] ), $now );

        $this->repo->aprobar( $idB, 42, null, $now );

        $pendientes = $this->repo->listPendientes( self::SEASON_ID );
        $this->assertCount( 1, $pendientes );
        $this->assertSame( $idA, (int) $pendientes[0]['id'] );

        $aprobadas = $this->repo->listAprobadas( self::SEASON_ID );
        $this->assertCount( 1, $aprobadas );
        $this->assertSame( $idB, (int) $aprobadas[0]['id'] );

        $this->assertNotContains( $idOtraSeason, array_map( static fn ( $r ) => (int) $r['id'], array_merge( $pendientes, $aprobadas ) ) );
    }

    /**
     * listByEquipo() backs the CAPTAIN's own request tray (slice 4d's
     * `GET /cambios/solicitudes`) — unlike listPendientes()/listAprobadas(),
     * which exist for the PROCESS OWNER's tray and are scoped to one estado
     * at a time, a captain must see every solicitud their team has ever
     * made, in ANY estado.
     */
    public function test_list_by_equipo_returns_every_estado_for_the_team_only(): void {
        $now = '2026-05-27 10:00:00';

        $idA          = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), $now );
        $idB          = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 2, 889, 5, time() ), 777, Dictamen::from( [] ), $now );
        $idOtroEquipo = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 200, 3, 890, 5, time() ), 777, Dictamen::from( [] ), $now );

        $this->repo->aprobar( $idB, 42, null, $now );
        $this->repo->rechazar( $idA, 42, 'no cumple', $now );

        $solicitudes = $this->repo->listByEquipo( self::SEASON_ID, 100 );

        $this->assertCount( 2, $solicitudes );
        $ids = array_map( static fn ( $r ) => (int) $r['id'], $solicitudes );
        $this->assertContains( $idA, $ids );
        $this->assertContains( $idB, $ids );
        $this->assertNotContains( $idOtroEquipo, $ids );

        $estados = array_column( $solicitudes, 'estado' );
        sort( $estados );
        $this->assertSame( [ EstadoSolicitud::APROBADA, EstadoSolicitud::RECHAZADA ], $estados );
    }

    // -------------------------------------------------------------------------
    // aprobar() / rechazar() / anular() — the estado machine
    // -------------------------------------------------------------------------

    public function test_aprobar_no_crea_ni_modifica_ninguna_ocupacion(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );

        $antes = $this->plazaRepository->findOcupacionVigente( $plazaId );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 1, time() );
        $id        = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->repo->aprobar( $id, 42, 'ok', '2026-05-27 11:00:00' );

        $despues = $this->plazaRepository->findOcupacionVigente( $plazaId );

        $this->assertSame( (int) $antes['id'], (int) $despues['id'] );
        $this->assertSame( (int) $antes['player_id'], (int) $despues['player_id'] );
        $this->assertCount( 1, $this->plazaRepository->listOcupaciones( $plazaId ) );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::APROBADA, $row['estado'] );
        $this->assertSame( 42, (int) $row['resuelta_por'] );
        $this->assertSame( 'ok', $row['nota'] );
    }

    public function test_rechazar_desde_pendiente(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->repo->rechazar( $id, 42, 'no corresponde', '2026-05-27 11:00:00' );

        $this->assertSame( EstadoSolicitud::RECHAZADA, $this->repo->findSolicitud( $id )['estado'] );
        $this->assertTrue( $this->eventLog->has( 'solicitud.rechazada' ) );
    }

    public function test_anular_desde_aprobada(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        $this->repo->anular( $id, 42, 'cambio de opinión', '2026-05-27 12:00:00' );

        $this->assertSame( EstadoSolicitud::ANULADA, $this->repo->findSolicitud( $id )['estado'] );
        $this->assertTrue( $this->eventLog->has( 'solicitud.anulada' ) );
    }

    public function test_aprobar_una_solicitud_ya_aprobada_lanza(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        $this->expectException( TransicionInvalidaException::class );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 12:00:00' );
    }

    public function test_rechazar_una_solicitud_publicada_lanza(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );
        $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00' );

        $this->expectException( TransicionInvalidaException::class );
        $this->repo->rechazar( $id, 42, null, '2026-05-29 10:00:00' );
    }

    public function test_aprobar_solicitud_inexistente_lanza(): void {
        $this->expectException( \RuntimeException::class );
        $this->repo->aprobar( 999999, 42, null, '2026-05-27 10:00:00' );
    }

    // -------------------------------------------------------------------------
    // publicarLote()
    // -------------------------------------------------------------------------

    public function test_publicar_lote_aplica_sustitucion(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );
        $this->assertTrue( $dictamen->procede() );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['publicadas'] );
        $this->assertSame( [], $resultado['no_publicadas'] );
        $this->assertSame( [], $resultado['divergencias'] );

        $vigente = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( 888, (int) $vigente['player_id'] );
        $this->assertSame( 'reemplazada', $this->plazaRepository->listOcupaciones( $plazaId )[0]['cerrada_por'] );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::PUBLICADA, $row['estado'] );
        $this->assertNotNull( $row['dictamen_aplicado'] );
        $this->assertTrue( DictamenSnapshot::fromJson( (string) $row['dictamen_aplicado'] )->procede() );

        $this->assertTrue( $this->eventLog->has( 'solicitud.publicada' ) );
    }

    public function test_publicar_lote_aplica_regreso(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-01-01' );
        $this->seedFecha( 2, self::SEASON_ID, '2026-01-08', 'jugada' );
        $this->seedFecha( 3, self::SEASON_ID, '2026-01-15', 'jugada' );
        $this->seedFecha( 4, self::SEASON_ID, '2026-01-22', 'jugada' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-01-29', 'jugada' );
        $regresoPlayDate = '2026-06-13';
        $this->seedFecha( 8, self::SEASON_ID, $regresoPlayDate );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-01-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 999, 2, 'reemplazada', '2026-01-05 00:00:00' );

        $epoch     = $this->instanteEnPlazo( $regresoPlayDate );
        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 8, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );
        $this->assertTrue( $dictamen->procede() );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-06-10 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-06-10 11:00:00' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-06-12 09:00:00' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['publicadas'] );

        $vigente = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( 777, (int) $vigente['player_id'] );

        $this->assertSame( EstadoSolicitud::PUBLICADA, $this->repo->findSolicitud( $id )['estado'] );
    }

    public function test_publicar_lote_devuelve_vacio_cuando_no_recibe_ids(): void {
        $resultado = $this->repo->publicarLote( [], 42, '2026-05-29 09:00:00' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [], $resultado['publicadas'] );
        $this->assertSame( [], $resultado['no_publicadas'] );
    }

    public function test_publicar_lote_donde_una_ya_no_procede_no_publica_ninguna(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, 101, 555, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $plazaC = $this->plazaRepository->openPlaza( self::SEASON_ID, 102, 333, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );

        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 999, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solA  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 5, $epoch );
        $dictA = $this->pipeline->evaluate( $solA );
        $this->assertTrue( $dictA->procede() );
        $idA = $this->repo->crear( $solA, 777, $dictA, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00' );

        $solB  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 101, $plazaB, 999, 5, $epoch );
        $dictB = $this->pipeline->evaluate( $solB );
        $this->assertTrue( $dictB->procede() );
        $idB = $this->repo->crear( $solB, 555, $dictB, '2026-05-27 10:05:00' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00' );

        // Between approval and the lote, an UNRELATED write occupies 888
        // elsewhere — exactly the kind of drift publicarLote() must catch.
        $this->plazaRepository->succeedOcupacion( $plazaC, 888, 5, 'reemplazada', '2026-05-28 10:00:00' );

        $resultado = $this->repo->publicarLote( [ $idB, $idA ], 42, '2026-05-29 09:00:00' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [], $resultado['publicadas'] );
        $this->assertSame( [ $idB, $idA ], $resultado['no_publicadas'] );
        $this->assertStringContainsString( (string) $idA, $resultado['motivo'] );

        // Nothing applied — plaza B's own (still-valid) change did NOT go through.
        $vigenteB = $this->plazaRepository->findOcupacionVigente( $plazaB );
        $this->assertSame( 555, (int) $vigenteB['player_id'] );

        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $idA )['estado'] );
        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $idB )['estado'] );

        $this->assertTrue( $this->eventLog->has( 'solicitud.lote_abortado' ) );
    }

    public function test_publicar_lote_aborta_si_una_solicitud_no_esta_aprobada(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );

        $idPendiente = $this->repo->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );

        $resultado = $this->repo->publicarLote( [ $idPendiente ], 42, '2026-05-29 09:00:00' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [ $idPendiente ], $resultado['no_publicadas'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $this->repo->findSolicitud( $idPendiente )['estado'] );
    }

    public function test_publicar_lote_detecta_y_registra_divergencia_cuando_el_dictamen_cambio(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );

        // Deliberately mismatched snapshot: the ORIGINAL dictamen objected
        // (simulating a fact that was true on Wednesday and cleared by
        // Friday), even though the REAL context is — and stays — clean.
        $dictamenOriginalDivergente = Dictamen::from( [ new Motivo( 'entrante_puntaje_indeterminado', 'sin puntaje al momento de pedir' ) ] );

        $id = $this->repo->crear( $solicitud, 777, $dictamenOriginalDivergente, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['publicadas'] );
        $this->assertSame( [ $id ], $resultado['divergencias'] );

        $row              = $this->repo->findSolicitud( $id );
        $snapshotAplicado = DictamenSnapshot::fromJson( (string) $row['dictamen_aplicado'] );
        $this->assertTrue( $snapshotAplicado->procede() );
        $this->assertSame( [], $snapshotAplicado->motivoCodigos() );
    }

    public function test_publicar_lote_hace_rollback_si_una_escritura_falla_a_mitad_del_lote(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, 101, 555, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );

        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 999, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solA  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 5, $epoch );
        $dictA = $this->pipeline->evaluate( $solA );
        $idA   = $this->repo->crear( $solA, 777, $dictA, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00' );

        $solB  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 101, $plazaB, 999, 5, $epoch );
        $dictB = $this->pipeline->evaluate( $solB );
        $idB   = $this->repo->crear( $solB, 555, $dictB, '2026-05-27 10:05:00' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00' );

        global $wpdb;
        // Let the FIRST plaza's write succeed, fail the SECOND — proving the
        // whole lote rolls back, not just the one whose write actually failed.
        $failingWpdb      = $this->wpdbThatFailsOnNthOcupacionInsert( $wpdb, 2 );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $failingRepo      = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->pipeline, $this->eventLog );

        $resultado = $failingRepo->publicarLote( [ $idA, $idB ], 42, '2026-05-29 09:00:00' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [], $resultado['publicadas'] );
        $this->assertSame( [ $idA, $idB ], $resultado['no_publicadas'] );

        // Nothing applied — including plaza A's write, which DID succeed
        // before plaza B's failed and forced the rollback.
        $vigenteA = $this->plazaRepository->findOcupacionVigente( $plazaA );
        $vigenteB = $this->plazaRepository->findOcupacionVigente( $plazaB );
        $this->assertSame( 777, (int) $vigenteA['player_id'] );
        $this->assertSame( 555, (int) $vigenteB['player_id'] );
        $this->assertCount( 1, $this->plazaRepository->listOcupaciones( $plazaA ) );
        $this->assertCount( 1, $this->plazaRepository->listOcupaciones( $plazaB ) );

        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $idA )['estado'] );
        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $idB )['estado'] );
    }

    // -------------------------------------------------------------------------
    // publicarLote() — corrupted dictamen_original, season guard, ocupacion_id
    // -------------------------------------------------------------------------

    /**
     * DictamenSnapshot::fromJson() used to run OUTSIDE the try/catch that
     * wraps re-evaluation — a single corrupted `dictamen_original` blew up
     * the whole request, unlogged, instead of aborting just this lote with
     * an explicit motivo.
     */
    public function test_publicar_lote_aborta_prolijamente_cuando_el_dictamen_original_es_json_corrupto(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );
        $this->assertTrue( $dictamen->procede() );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cambios_solicitud',
            [ 'dictamen_original' => '{esto no es json valido' ],
            [ 'id' => $id ]
        );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['no_publicadas'] );
        $this->assertSame( $id, $resultado['culprit_id'] );
        $this->assertStringContainsString( (string) $id, $resultado['motivo'] );

        // Nothing applied — the plaza's occupancy is untouched.
        $vigente = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( 777, (int) $vigente['player_id'] );

        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $id )['estado'] );
        $this->assertTrue( $this->eventLog->has( 'solicitud.lote_abortado' ) );
    }

    /**
     * Not exploitable today (the role is unique and global — see
     * CapitanAuthorizer), but the guard costs nothing and closes the gap
     * before it becomes one.
     */
    public function test_publicar_lote_aborta_cuando_las_solicitudes_pertenecen_a_distintas_temporadas(): void {
        $otherSeasonId = 12345;

        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedFecha( 101, $otherSeasonId, '2026-05-16' );
        $this->seedFecha( 105, $otherSeasonId, '2026-05-30' );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( $otherSeasonId, 200, 555, Puntaje::fromDecimal( 3.0 ), 'campo', 101, '2026-03-01 00:00:00' );

        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 999, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solA  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 5, $epoch );
        $dictA = $this->pipeline->evaluate( $solA );
        $this->assertTrue( $dictA->procede() );
        $idA = $this->repo->crear( $solA, 777, $dictA, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00' );

        $solB  = SolicitudDeCambio::sustitucion( $otherSeasonId, 200, $plazaB, 999, 105, $epoch );
        $dictB = $this->pipeline->evaluate( $solB );
        $this->assertTrue( $dictB->procede() );
        $idB = $this->repo->crear( $solB, 555, $dictB, '2026-05-27 10:05:00' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00' );

        $resultado = $this->repo->publicarLote( [ $idA, $idB ], 42, '2026-05-29 09:00:00' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [ $idA, $idB ], $resultado['no_publicadas'] );
        $this->assertNull( $resultado['culprit_id'] );
        $this->assertStringContainsString( 'temporada', $resultado['motivo'] );

        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $idA )['estado'] );
        $this->assertSame( EstadoSolicitud::APROBADA, $this->repo->findSolicitud( $idB )['estado'] );
    }

    /**
     * Undoing a wrongly-published lote must never again require
     * cross-referencing the EventLog by plaza_id and timestamp by hand.
     */
    public function test_publicar_lote_guarda_el_ocupacion_id_resultante_en_la_solicitud(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00' );

        $vigente = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $row     = $this->repo->findSolicitud( $id );

        $this->assertNotNull( $row['ocupacion_id'] );
        $this->assertSame( (int) $vigente['id'], (int) $row['ocupacion_id'] );
    }

    // -------------------------------------------------------------------------
    // publicarLote() — COMMIT failure must never read as success
    // -------------------------------------------------------------------------

    /**
     * @param \wpdb $real A live wpdb sharing THIS test's SQLite connection.
     */
    private function wpdbWhoseCommitFails( \wpdb $real ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function query( string $sql ): int|false {
                if ( str_contains( $sql, 'COMMIT' ) && ! str_contains( $sql, 'ROLLBACK' ) ) {
                    $this->last_error = 'simulated commit failure for test';

                    return false;
                }

                return parent::query( $sql );
            }
        };
    }

    /**
     * A COMMIT that fails must throw — never let the caller believe the
     * lote published when the database itself could not confirm it (see
     * Support\OpensTransactions::commitTransaction()'s docblock).
     */
    public function test_publicar_lote_lanza_cuando_el_commit_falla(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00' );

        global $wpdb;
        $failingWpdb      = $this->wpdbWhoseCommitFails( $wpdb );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $failingRepo      = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->pipeline, $this->eventLog );

        try {
            $failingRepo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00' );
            $this->fail( 'Expected a RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'must never believe', $e->getMessage() );
        } finally {
            // The fake wpdb intercepted COMMIT without ever forwarding it to
            // the REAL, shared PDO connection — so that connection is still
            // sitting inside an open transaction. Clean it up here, or every
            // OTHER test sharing $wpdb after this one fails to even START a
            // transaction of its own ("cannot start a transaction within a
            // transaction").
            $wpdb->query( 'ROLLBACK' );
        }
    }
}
