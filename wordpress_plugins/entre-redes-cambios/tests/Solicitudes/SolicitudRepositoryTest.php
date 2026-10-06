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
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
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
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
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

    // -------------------------------------------------------------------------
    // crear() — saliente_player_id write-time capture
    // -------------------------------------------------------------------------

    public function test_crear_guarda_el_ocupante_vigente_como_saliente_player_id(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 1, time() );
        $id        = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( 777, (int) $row['saliente_player_id'] );
    }

    /**
     * THE WHOLE POINT of storing `saliente_player_id` at write time instead
     * of deriving it later from the plaza's current occupant (see
     * `Migrations\InitialSchema::sqlCambiosSolicitud()`'s own docblock): once
     * the chain advances past the moment a solicitud was created, re-reading
     * "who occupies this plaza now" would silently relabel an OLD solicitud
     * with a occupant who was not even there when it was made. This pins the
     * value by advancing the chain AFTER `crear()` and asserting the stored
     * column never moved.
     */
    public function test_saliente_player_id_no_cambia_cuando_la_cadena_avanza_despues_de_crear(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 2, self::SEASON_ID, '2026-05-23' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 1, time() );
        $id        = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->assertSame( 777, (int) $this->repo->findSolicitud( $id )['saliente_player_id'] );

        // The chain advances AFTER the solicitud was created — the plaza's
        // vigent occupant is now 888, not 777.
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-05-28 00:00:00' );
        $vigenteAhora = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( 888, (int) $vigenteAhora['player_id'], 'Fixture sanity: the chain really did advance.' );

        // The already-persisted solicitud must still read the ORIGINAL
        // saliente — never the plaza's new current occupant.
        $this->assertSame(
            777,
            (int) $this->repo->findSolicitud( $id )['saliente_player_id'],
            'saliente_player_id must stay pinned to who occupied the plaza AT CREATION TIME.'
        );
    }

    public function test_crear_guarda_saliente_player_id_null_cuando_la_plaza_no_tiene_ocupacion_vigente(): void {
        // No openPlaza() call — plaza_id 999999 has no cambios_plaza row and
        // therefore no cambios_ocupacion chain at all.
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 999999, 888, 5, time() );
        $id        = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $row = $this->repo->findSolicitud( $id );

        // assertArrayHasKey FIRST — without it, a missing 'saliente_player_id'
        // column/key would read back as PHP's own "undefined array key" null,
        // which assertNull() alone cannot tell apart from a column that
        // genuinely exists and was explicitly stored as NULL.
        $this->assertArrayHasKey( 'saliente_player_id', $row );
        $this->assertNull( $row['saliente_player_id'] );
    }

    public function test_crear_regreso_tambien_guarda_el_ocupante_vigente_como_saliente(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-01-01' );
        $this->seedFecha( 2, self::SEASON_ID, '2026-01-08' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 999, 2, 'reemplazada', '2026-01-05 00:00:00' );

        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 2, time() );
        $id        = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        // The suplente (999) currently holds the plaza — THEY are who leaves
        // when the titular (777) comes back, never the titular itself.
        $this->assertSame( 999, (int) $this->repo->findSolicitud( $id )['saliente_player_id'] );
    }

    public function test_list_pendientes_and_aprobadas_filter_by_season_and_estado(): void {
        $now = '2026-05-27 10:00:00';

        $idA           = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), $now );
        $idB           = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 2, 889, 5, time() ), 777, Dictamen::from( [] ), $now );
        $idOtraSeason  = $this->repo->crear( SolicitudDeCambio::sustitucion( 999, 100, 3, 890, 5, time() ), 777, Dictamen::from( [] ), $now );

        $this->repo->aprobar( $idB, 42, null, $now, 'Proceso Owner Test' );

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

        $this->repo->aprobar( $idB, 42, null, $now, 'Proceso Owner Test' );
        $this->repo->rechazar( $idA, 42, 'no cumple', $now, 'Proceso Owner Test' );

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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $antes = $this->plazaRepository->findOcupacionVigente( $plazaId );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 1, time() );
        $id        = $this->repo->crear( $solicitud, 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->repo->aprobar( $id, 42, 'ok', '2026-05-27 11:00:00', 'Proceso Owner Test' );

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

        $this->repo->rechazar( $id, 42, 'no corresponde', '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $this->assertSame( EstadoSolicitud::RECHAZADA, $this->repo->findSolicitud( $id )['estado'] );
        $this->assertTrue( $this->eventLog->has( 'solicitud.rechazada' ) );
    }

    public function test_anular_desde_aprobada(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $this->repo->anular( $id, 42, 'cambio de opinión', '2026-05-27 12:00:00', 'Proceso Owner Test' );

        $this->assertSame( EstadoSolicitud::ANULADA, $this->repo->findSolicitud( $id )['estado'] );
        $this->assertTrue( $this->eventLog->has( 'solicitud.anulada' ) );
    }

    public function test_aprobar_una_solicitud_ya_aprobada_lanza(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $this->expectException( TransicionInvalidaException::class );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 12:00:00', 'Proceso Owner Test' );
    }

    public function test_rechazar_una_solicitud_publicada_lanza(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );
        $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

        $this->expectException( TransicionInvalidaException::class );
        $this->repo->rechazar( $id, 42, null, '2026-05-29 10:00:00', 'Proceso Owner Test' );
    }

    public function test_aprobar_solicitud_inexistente_lanza(): void {
        $this->expectException( \RuntimeException::class );
        $this->repo->aprobar( 999999, 42, null, '2026-05-27 10:00:00', 'Proceso Owner Test' );
    }

    // -------------------------------------------------------------------------
    // publicarLote()
    // -------------------------------------------------------------------------

    public function test_publicar_lote_aplica_sustitucion(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );
        $this->assertTrue( $dictamen->procede() );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 999, 2, 'reemplazada', '2026-01-05 00:00:00' );

        $epoch     = $this->instanteEnPlazo( $regresoPlayDate );
        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 8, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );
        $this->assertTrue( $dictamen->procede() );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-06-10 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-06-10 11:00:00', 'Proceso Owner Test' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-06-12 09:00:00', 'Proceso Owner Test' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['publicadas'] );

        $vigente = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( 777, (int) $vigente['player_id'] );

        $this->assertSame( EstadoSolicitud::PUBLICADA, $this->repo->findSolicitud( $id )['estado'] );
    }

    public function test_publicar_lote_devuelve_vacio_cuando_no_recibe_ids(): void {
        $resultado = $this->repo->publicarLote( [], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [], $resultado['publicadas'] );
        $this->assertSame( [], $resultado['no_publicadas'] );
    }

    public function test_publicar_lote_donde_una_ya_no_procede_no_publica_ninguna(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, 101, 555, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaC = $this->plazaRepository->openPlaza( self::SEASON_ID, 102, 333, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 999, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solA  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 5, $epoch );
        $dictA = $this->pipeline->evaluate( $solA );
        $this->assertTrue( $dictA->procede() );
        $idA = $this->repo->crear( $solA, 777, $dictA, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $solB  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 101, $plazaB, 999, 5, $epoch );
        $dictB = $this->pipeline->evaluate( $solB );
        $this->assertTrue( $dictB->procede() );
        $idB = $this->repo->crear( $solB, 555, $dictB, '2026-05-27 10:05:00' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00', 'Proceso Owner Test' );

        // Between approval and the lote, an UNRELATED write occupies 888
        // elsewhere — exactly the kind of drift publicarLote() must catch.
        $this->plazaRepository->succeedOcupacion( $plazaC, 888, 5, 'reemplazada', '2026-05-28 10:00:00' );

        $resultado = $this->repo->publicarLote( [ $idB, $idA ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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

        $resultado = $this->repo->publicarLote( [ $idPendiente ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [ $idPendiente ], $resultado['no_publicadas'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $this->repo->findSolicitud( $idPendiente )['estado'] );
    }

    public function test_publicar_lote_detecta_y_registra_divergencia_cuando_el_dictamen_cambio(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );

        // Deliberately mismatched snapshot: the ORIGINAL dictamen objected
        // (simulating a fact that was true on Wednesday and cleared by
        // Friday), even though the REAL context is — and stays — clean.
        $dictamenOriginalDivergente = Dictamen::from( [ new Motivo( 'entrante_puntaje_indeterminado', 'sin puntaje al momento de pedir' ) ] );

        $id = $this->repo->crear( $solicitud, 777, $dictamenOriginalDivergente, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, 101, 555, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 999, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solA  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 5, $epoch );
        $dictA = $this->pipeline->evaluate( $solA );
        $idA   = $this->repo->crear( $solA, 777, $dictA, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $solB  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 101, $plazaB, 999, 5, $epoch );
        $dictB = $this->pipeline->evaluate( $solB );
        $idB   = $this->repo->crear( $solB, 555, $dictB, '2026-05-27 10:05:00' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00', 'Proceso Owner Test' );

        global $wpdb;
        // Let the FIRST plaza's write succeed, fail the SECOND — proving the
        // whole lote rolls back, not just the one whose write actually failed.
        $failingWpdb      = $this->wpdbThatFailsOnNthOcupacionInsert( $wpdb, 2 );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $failingRepo      = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->pipeline, $this->eventLog );

        $resultado = $failingRepo->publicarLote( [ $idA, $idB ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );
        $this->assertTrue( $dictamen->procede() );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cambios_solicitud',
            [ 'dictamen_original' => '{esto no es json valido' ],
            [ 'id' => $id ]
        );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( $otherSeasonId, 200, 555, Puntaje::fromDecimal( 3.0 ), 101, '2026-03-01 00:00:00' );

        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 999, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solA  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 5, $epoch );
        $dictA = $this->pipeline->evaluate( $solA );
        $this->assertTrue( $dictA->procede() );
        $idA = $this->repo->crear( $solA, 777, $dictA, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $solB  = SolicitudDeCambio::sustitucion( $otherSeasonId, 200, $plazaB, 999, 105, $epoch );
        $dictB = $this->pipeline->evaluate( $solB );
        $this->assertTrue( $dictB->procede() );
        $idB = $this->repo->crear( $solB, 555, $dictB, '2026-05-27 10:05:00' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00', 'Proceso Owner Test' );

        $resultado = $this->repo->publicarLote( [ $idA, $idB ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $this->repo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        global $wpdb;
        $failingWpdb      = $this->wpdbWhoseCommitFails( $wpdb );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $failingRepo      = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->pipeline, $this->eventLog );

        try {
            $failingRepo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );
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

    // -------------------------------------------------------------------------
    // cambios_decision — the append-only decision log
    // -------------------------------------------------------------------------

    /**
     * @param \wpdb $real A live wpdb sharing THIS test's SQLite connection.
     */
    private function wpdbThatFailsOnNthInsertInto( \wpdb $real, string $table, int $failOnCall ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $table, $failOnCall ) extends \wpdb {
            private int $calls = 0;
            private string $table;
            private int $failOnCall;

            public function __construct( \PDO $pdo, string $prefix, string $table, int $failOnCall ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix     = $prefix;
                $this->table      = $table;
                $this->failOnCall = $failOnCall;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                if ( str_ends_with( $table, $this->table ) ) {
                    $this->calls++;

                    if ( $this->calls === $this->failOnCall ) {
                        $this->last_error = 'simulated insert failure for test (' . $this->table . ' insert #' . $this->calls . ')';
                        return false;
                    }
                }

                return parent::insert( $table, $data, $format );
            }
        };
    }

    public function test_aprobar_y_luego_rechazar_deja_dos_decisiones_no_pisa_la_anterior(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->repo->aprobar( $id, 42, 'aprobado por ahora', '2026-05-27 11:00:00', 'Ana Pérez' );
        $this->repo->rechazar( $id, 42, 'me arrepentí', '2026-05-28 09:00:00', 'Beatriz Gómez' );

        $decisiones = $this->repo->listDecisiones( $id );

        $this->assertCount( 2, $decisiones, 'Two separate decisions must leave TWO rows, not one overwritten.' );

        $this->assertSame( EstadoSolicitud::APROBADA, $decisiones[0]['accion'] );
        $this->assertSame( 'Ana Pérez', $decisiones[0]['decidida_por_nombre'] );
        $this->assertSame( 'aprobado por ahora', $decisiones[0]['nota'] );

        $this->assertSame( EstadoSolicitud::RECHAZADA, $decisiones[1]['accion'] );
        $this->assertSame( 'Beatriz Gómez', $decisiones[1]['decidida_por_nombre'] );
        $this->assertSame( 'me arrepentí', $decisiones[1]['nota'] );

        // The FIRST decision's snapshot must still read 'Ana Pérez' after the
        // SECOND decision was appended — proving the name is never rewritten
        // once recorded, even by a later decision on the very same solicitud.
        $this->assertSame( 'Ana Pérez', $decisiones[0]['decidida_por_nombre'] );
    }

    /**
     * The name is a SNAPSHOT captured at decision time, never re-derived
     * later — see `cambios_decision`'s own docblock in `InitialSchema`. This
     * is proven here without needing a real `wp_users` row: the caller (this
     * test) simply passes a DIFFERENT name string on a second, independent
     * decision, and the first row must still read exactly what was passed
     * the first time — nothing re-reads or rewrites it afterwards.
     */
    public function test_decidida_por_nombre_es_un_snapshot_no_se_actualiza_retroactivamente(): void {
        $idA = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );
        $idB = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 2, 889, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00', 'Ana Pérez' );

        // The same WP user (id 42) is now presented under a different name —
        // exactly what a real WP profile rename or a different session would
        // look like from this repository's point of view.
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00', 'Ana P. (renombrada)' );

        $decisionesA = $this->repo->listDecisiones( $idA );
        $decisionesB = $this->repo->listDecisiones( $idB );

        $this->assertSame( 'Ana Pérez', $decisionesA[0]['decidida_por_nombre'] );
        $this->assertSame( 'Ana P. (renombrada)', $decisionesB[0]['decidida_por_nombre'] );
    }

    public function test_publicar_lote_registra_una_decision_por_cada_solicitud_publicada(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );

        $plazaIdA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaIdB = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 778, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );
        $this->seedPuntaje( 889, 2.5 );

        $epoch = $this->instanteEnPlazo( '2026-05-30' );

        $solicitudA = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaIdA, 888, 5, $epoch );
        $solicitudB = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaIdB, 889, 5, $epoch );

        $idA = $this->repo->crear( $solicitudA, 777, $this->pipeline->evaluate( $solicitudA ), '2026-05-27 10:00:00' );
        $idB = $this->repo->crear( $solicitudB, 777, $this->pipeline->evaluate( $solicitudB ), '2026-05-27 10:05:00' );

        $this->repo->aprobar( $idA, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );
        $this->repo->aprobar( $idB, 42, null, '2026-05-27 11:05:00', 'Proceso Owner Test' );

        $resultado = $this->repo->publicarLote( [ $idA, $idB ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

        $this->assertFalse( $resultado['abortado'] );

        foreach ( [ $idA, $idB ] as $id ) {
            $decisiones = $this->repo->listDecisiones( $id );
            $this->assertCount( 2, $decisiones, "Solicitud {$id} should have its 'aprobada' AND its 'publicada' decision." );
            $this->assertSame( EstadoSolicitud::APROBADA, $decisiones[0]['accion'] );
            $this->assertSame( EstadoSolicitud::PUBLICADA, $decisiones[1]['accion'] );
            $this->assertSame( 'Proceso Owner Test', $decisiones[1]['decidida_por_nombre'] );
        }
    }

    public function test_list_decisiones_devuelve_el_historial_completo_en_orden_cronologico(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        $this->repo->aprobar( $id, 42, 'ok', '2026-05-27 11:00:00', 'Ana Pérez' );
        $this->repo->rechazar( $id, 43, 'no, en realidad no', '2026-05-28 09:00:00', 'Beatriz Gómez' );

        $decisiones = $this->repo->listDecisiones( $id );

        $this->assertSame(
            [ EstadoSolicitud::APROBADA, EstadoSolicitud::RECHAZADA ],
            array_column( $decisiones, 'accion' )
        );
        $this->assertSame(
            [ '2026-05-27 11:00:00', '2026-05-28 09:00:00' ],
            array_column( $decisiones, 'decidida_at' )
        );
    }

    /**
     * If the `cambios_decision` insert fails, the `cambios_solicitud` estado
     * flip must NOT persist either — see `transicionar()`'s own docblock:
     * the two are one atomic unit, never one without the other.
     */
    public function test_aprobar_no_deja_el_estado_cambiado_si_falla_el_registro_de_la_decision(): void {
        $id = $this->repo->crear( SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 5, time() ), 777, Dictamen::from( [] ), '2026-05-27 10:00:00' );

        global $wpdb;
        $failingWpdb      = $this->wpdbThatFailsOnNthInsertInto( $wpdb, 'cambios_decision', 1 );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $failingRepo      = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->pipeline, $this->eventLog );

        try {
            $failingRepo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );
            $this->fail( 'Expected a SolicitudPersistenceException.' );
        } catch ( \RuntimeException $e ) {
            // Expected — either SolicitudPersistenceException (decision
            // insert) surfaces here.
        }

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'], 'estado must be rolled back when the decision row could not be written.' );
        $this->assertSame( [], $this->repo->listDecisiones( $id ), 'No decision row should exist either — both writes are one atomic unit.' );
    }

    /**
     * Same atomicity guarantee as above, at `publicarLote()`'s scale: if the
     * `cambios_decision` insert fails for ANY solicitud mid-lote, nothing in
     * the whole lote is applied — no ocupación changes, no decision rows,
     * exactly like any other mid-lote write failure (see
     * `test_publicar_lote_hace_rollback_si_una_escritura_falla_a_mitad_del_lote`).
     */
    public function test_publicar_lote_no_aplica_nada_si_falla_el_registro_de_una_decision(): void {
        $this->seedFecha( 1, self::SEASON_ID, '2026-05-16' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $epoch     = $this->instanteEnPlazo( '2026-05-30' );
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $epoch );
        $dictamen  = $this->pipeline->evaluate( $solicitud );

        $id = $this->repo->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->repo->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $antes = $this->plazaRepository->findOcupacionVigente( $plazaId );

        global $wpdb;
        $failingWpdb      = $this->wpdbThatFailsOnNthInsertInto( $wpdb, 'cambios_decision', 1 );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $failingRepo      = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->pipeline, $this->eventLog );

        $resultado = $failingRepo->publicarLote( [ $id ], 42, '2026-05-29 09:00:00', 'Proceso Owner Test' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( $id, $resultado['culprit_id'] );

        $despues = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( (int) $antes['player_id'], (int) $despues['player_id'], 'No ocupacion change should survive an aborted lote.' );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::APROBADA, $row['estado'], 'estado must remain aprobada — never publicada — when the lote aborts.' );
        $this->assertCount( 1, $this->repo->listDecisiones( $id ), 'Only the original "aprobada" decision should exist — no "publicada" row from the aborted attempt.' );
    }
}
