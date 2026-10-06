<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the GROUPED goalkeeper reassignment
 * (`Dictamen\SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO`) — see
 * `Solicitudes\SolicitudRepository`'s class docblock, "GROUPED REQUESTS".
 * Built against the REAL DictamenPipeline/DictamenContextAssembler/
 * PlazaRepository, same style as `SolicitudRepositoryTest`, so
 * `publicarLote()`'s fresh re-evaluation is exercised against the real ten
 * dictamen rules, not a stub.
 */
class SolicitudRepositoryReasignacionArqueroTest extends TestCase {

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;

    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private InMemoryEventLog $eventLog;
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

        $this->repo = $this->buildRepo();

        $this->seedFecha( 1, self::SEASON_ID, '2026-01-03' );
    }

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta', 'cambios_settings' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wp_test_position_terms = []; // see tests/wp-shim.php's wp_get_object_terms() docblock
        InitialSchema::up(); // restore settings seeds for any test that runs after this file
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** Builds a SolicitudRepository wired against THIS test's current Settings row. */
    private function buildRepo(): SolicitudRepository {
        global $wpdb;

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog
        );

        $pipeline = new DictamenPipeline(
            $assembler,
            $this->eventLog,
            null,
            false,
            $this->settings->exencionArcoActiva()
        );

        return new SolicitudRepository( $wpdb, $this->plazaRepository, $pipeline, $this->eventLog );
    }

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

    private function putSetting( string $key, string $value ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}cambios_settings SET setting_value = %s WHERE setting_key = %s",
                $value,
                $key
            )
        );
    }

    private function instanteEnPlazo( string $playDate ): int {
        $plazos = PlazosCalculator::computeUtc( $playDate, $this->settings->plazosOffsets(), $this->settings->timezone() );

        return ( new \DateTimeImmutable( $plazos['apertura_solicitudes'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 3600;
    }

    /**
     * @return array{plazaArcoId: int, plazaCampoId: int} The goal plaza
     *         (titular 111, techo 2.5) and the field plaza (titular 222,
     *         techo 3.0 — matching 222's own seeded puntaje, "a plaza's
     *         techo is snapshotted from its titular"). Both on fecha 1 /
     *         team 100 / season 359.
     */
    private function seedPlazasDeReasignacion(): array {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 111 => [ 3 ] ]; // 111 is the goalkeeper (term 3)

        $plazaArcoId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );

        $wp_test_position_terms = []; // 222 is an ordinary field player
        $plazaCampoId           = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );

        $this->seedPuntaje( 222, 3.0 );

        return [ 'plazaArcoId' => $plazaArcoId, 'plazaCampoId' => $plazaCampoId ];
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
    // crearReasignacionArquero()
    // -------------------------------------------------------------------------

    public function test_crear_reasignacion_arquero_persiste_tipo_y_columnas_nuevas(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 2.5 );

        $instante = $this->instanteEnPlazo( '2026-05-30' );

        $id = $this->repo->crearReasignacionArquero(
            self::SEASON_ID,
            self::TEAM_ID,
            $plazas['plazaArcoId'],
            222,
            $plazas['plazaCampoId'],
            333,
            5,
            $instante,
            777,
            '2026-05-27 10:00:00'
        );

        $row = $this->repo->findSolicitud( $id );
        $this->assertNotNull( $row );
        $this->assertSame( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO, $row['tipo'] );
        $this->assertSame( $plazas['plazaArcoId'], (int) $row['plaza_id'] );
        $this->assertSame( 222, (int) $row['entrante_player_id'] );
        $this->assertSame( 111, (int) $row['saliente_player_id'], 'The current goalkeeper (111) must be captured as the saliente for movement 1.' );
        $this->assertSame( $plazas['plazaCampoId'], (int) $row['plaza_campo_id'] );
        $this->assertSame( 333, (int) $row['entrante_campo_player_id'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );

        $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );
        $this->assertTrue( $snapshot->procede(), 'Within techo, exemption ON by default — this grouped request must procede.' );
    }

    /**
     * THE test the brief asked for: a grouped request whose titular EXCEEDS
     * the goal plaza's techo is ACCEPTED when the exemption is on, and
     * REJECTED when it is off.
     */
    public function test_titular_sobre_el_techo_del_arco_es_aceptado_con_exencion_y_rechazado_sin_ella(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 111 => [ 3 ] ];
        $plazaArcoId            = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );

        $wp_test_position_terms = [];
        // 222's OWN plaza has a techo of 5.0 (so he is a legitimately strong
        // field titular) — well above the goal plaza's 2.5.
        $plazaCampoId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 222, Puntaje::fromDecimal( 5.0 ), 1, '2026-01-01 00:00:00' );
        $this->seedPuntaje( 222, 5.0 );

        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 2.5 );
        $instante = $this->instanteEnPlazo( '2026-05-30' );

        // --- Exemption ON (the seeded default) ---
        $idOn = $this->repo->crearReasignacionArquero(
            self::SEASON_ID, self::TEAM_ID, $plazaArcoId, 222, $plazaCampoId, 333, 5, $instante, 777, '2026-05-27 10:00:00'
        );
        $snapshotOn = DictamenSnapshot::fromJson( (string) $this->repo->findSolicitud( $idOn )['dictamen_original'] );
        $this->assertTrue( $snapshotOn->procede(), 'With the exemption ON, a titular (5.0) over the goal plaza\'s techo (2.5) must still procede.' );

        // --- Exemption OFF ---
        $this->putSetting( 'exencion_arco_activa', '0' );
        $repoSinExencion = $this->buildRepo();

        $idOff = $repoSinExencion->crearReasignacionArquero(
            self::SEASON_ID, self::TEAM_ID, $plazaArcoId, 222, $plazaCampoId, 333, 5, $instante, 777, '2026-05-27 10:00:00'
        );
        $snapshotOff = DictamenSnapshot::fromJson( (string) $repoSinExencion->findSolicitud( $idOff )['dictamen_original'] );
        $this->assertFalse( $snapshotOff->procede(), 'With the exemption OFF, the ordinary techo must reject this same request.' );
        $this->assertContains( 'puntaje_excede_techo', $snapshotOff->motivoCodigos() );
    }

    // -------------------------------------------------------------------------
    // publicarLote() — atomicity and chain correctness
    // -------------------------------------------------------------------------

    public function test_publicar_lote_aplica_ambos_movimientos_atomicamente_y_las_cadenas_quedan_correctas(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 2.5 );
        $instante = $this->instanteEnPlazo( '2026-05-30' );
        $now      = '2026-05-27 10:00:00';

        $id = $this->repo->crearReasignacionArquero(
            self::SEASON_ID, self::TEAM_ID, $plazas['plazaArcoId'], 222, $plazas['plazaCampoId'], 333, 5, $instante, 777, $now
        );

        $this->repo->aprobar( $id, 42, null, $now, 'Proceso Owner Test' );
        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 00:00:00', 'Proceso Owner Test' );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['publicadas'] );

        // Goal plaza: now occupied by the titular (222); the old goalkeeper
        // (111)'s link is closed as 'reemplazada'.
        $ocupacionesArco = $this->plazaRepository->listOcupaciones( $plazas['plazaArcoId'] );
        $this->assertCount( 2, $ocupacionesArco );
        $vigenteArco = $this->plazaRepository->findOcupacionVigente( $plazas['plazaArcoId'] );
        $this->assertSame( 222, (int) $vigenteArco['player_id'] );
        $cerradaArco = array_values( array_filter( $ocupacionesArco, static fn ( $o ) => 111 === (int) $o['player_id'] ) )[0];
        $this->assertSame( 'reemplazada', $cerradaArco['cerrada_por'] );
        $this->assertNotNull( $cerradaArco['fecha_hasta_id'] );

        // Field plaza: now occupied by the outside player (333); the
        // titular (222)'s link there is closed as 'reemplazada'.
        $ocupacionesCampo = $this->plazaRepository->listOcupaciones( $plazas['plazaCampoId'] );
        $this->assertCount( 2, $ocupacionesCampo );
        $vigenteCampo = $this->plazaRepository->findOcupacionVigente( $plazas['plazaCampoId'] );
        $this->assertSame( 333, (int) $vigenteCampo['player_id'] );
        $cerradaCampo = array_values( array_filter( $ocupacionesCampo, static fn ( $o ) => 222 === (int) $o['player_id'] ) )[0];
        $this->assertSame( 'reemplazada', $cerradaCampo['cerrada_por'] );
        $this->assertNotNull( $cerradaCampo['fecha_hasta_id'] );

        // Nobody — least of all the titular (222) — occupies two plazas: he
        // is vigent in the GOAL plaza (where he now belongs) and nowhere
        // else; he is NOT also still vigent in the field plaza he vacated.
        $this->assertSame( 222, (int) $vigenteArco['player_id'] );
        $this->assertNotSame( 222, (int) $vigenteCampo['player_id'] );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::PUBLICADA, $row['estado'] );
        $this->assertSame( (int) $vigenteArco['id'], (int) $row['ocupacion_id'] );
        $this->assertSame( (int) $vigenteCampo['id'], (int) $row['ocupacion_campo_id'] );
    }

    public function test_publicar_lote_hace_rollback_si_falla_el_segundo_movimiento(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 2.5 );
        $instante = $this->instanteEnPlazo( '2026-05-30' );
        $now      = '2026-05-27 10:00:00';

        $id = $this->repo->crearReasignacionArquero(
            self::SEASON_ID, self::TEAM_ID, $plazas['plazaArcoId'], 222, $plazas['plazaCampoId'], 333, 5, $instante, 777, $now
        );
        $this->repo->aprobar( $id, 42, null, $now, 'Proceso Owner Test' );

        global $wpdb;
        // Movement 2 (campo) runs first and performs the FIRST
        // cambios_ocupacion insert of the publish; movement 1 (arco)
        // performs the SECOND. Failing on call #2 proves a failure in
        // movement 1 rolls movement 2 back too — NEITHER applied.
        $failingWpdb      = $this->wpdbThatFailsOnNthOcupacionInsert( $wpdb, 2 );
        $failingPlazaRepo = new PlazaRepository( $failingWpdb, $this->eventLog );
        $repoConFallo     = new SolicitudRepository( $failingWpdb, $failingPlazaRepo, $this->repoPipeline(), $this->eventLog );

        $resultado = $repoConFallo->publicarLote( [ $id ], 42, '2026-05-29 00:00:00', 'Proceso Owner Test' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( [], $resultado['publicadas'] );
        $this->assertSame( [ $id ], $resultado['no_publicadas'] );

        // NEITHER movement applied: both plazas still show their ORIGINAL
        // occupants vigently.
        $vigenteArco  = $this->plazaRepository->findOcupacionVigente( $plazas['plazaArcoId'] );
        $vigenteCampo = $this->plazaRepository->findOcupacionVigente( $plazas['plazaCampoId'] );
        $this->assertSame( 111, (int) $vigenteArco['player_id'], 'Movement 1 must NOT have applied.' );
        $this->assertSame( 222, (int) $vigenteCampo['player_id'], 'Movement 2 must NOT have applied either, despite succeeding first.' );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::APROBADA, $row['estado'], 'The solicitud must remain aprobada, never half-published.' );
    }

    public function test_publicar_lote_refuses_a_grouped_request_that_no_longer_holds(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 2.5 );
        $instante = $this->instanteEnPlazo( '2026-05-30' );
        $now      = '2026-05-27 10:00:00';

        $id = $this->repo->crearReasignacionArquero(
            self::SEASON_ID, self::TEAM_ID, $plazas['plazaArcoId'], 222, $plazas['plazaCampoId'], 333, 5, $instante, 777, $now
        );
        $this->repo->aprobar( $id, 42, null, $now, 'Proceso Owner Test' );

        // The facts change AFTER approval: the field plaza (movement 2's
        // target) is closed by the operator before Friday's lote runs.
        $this->plazaRepository->closePlaza( $plazas['plazaCampoId'], '2026-05-28 00:00:00' );

        $resultado = $this->repo->publicarLote( [ $id ], 42, '2026-05-29 00:00:00', 'Proceso Owner Test' );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( $id, $resultado['culprit_id'] );
        $this->assertStringContainsString( 'ya no procede', (string) $resultado['motivo'] );

        // Nothing applied: both plazas' vigent occupants are unchanged.
        $this->assertSame( 111, (int) $this->plazaRepository->findOcupacionVigente( $plazas['plazaArcoId'] )['player_id'] );
        $this->assertSame( 222, (int) $this->plazaRepository->findOcupacionVigente( $plazas['plazaCampoId'] )['player_id'] );

        $row = $this->repo->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::APROBADA, $row['estado'] );
    }

    /** Rebuilds the pipeline this test's $this->repo already uses — needed to wire a replacement wpdb into a fresh SolicitudRepository. */
    private function repoPipeline(): DictamenPipeline {
        global $wpdb;

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog
        );

        return new DictamenPipeline( $assembler, $this->eventLog, null, false, $this->settings->exencionArcoActiva() );
    }
}
