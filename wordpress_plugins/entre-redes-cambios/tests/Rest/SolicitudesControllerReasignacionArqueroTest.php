<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\NotCaptainException;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Rest\SolicitudesController;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use PHPUnit\Framework\TestCase;

/**
 * REST-level tests for the GROUPED goalkeeper reassignment
 * (`tipo = 'reasignacion_arquero'`) over `POST /cambios/solicitudes` — see
 * `Rest\SolicitudesController`'s own class docblock, "`reasignacion_arquero`
 * — THE GROUPED REQUEST, EXPOSED ON THE SAME ROUTE (0.1.17)".
 *
 * *** WHY A REAL SolicitudRepository, UNLIKE THE REST OF
 * SolicitudesControllerTest ***
 * Every other suite in this directory mocks `SolicitudRepository` — this one
 * cannot: `crear()`'s grouped branch calls `crearReasignacionArquero()` and
 * then re-reads the SAME row via `findSolicitud()` to build the response's
 * `dictamen` (see `shapeDictamenDesdeSnapshot()`'s own docblock) — faking
 * that round trip would mean re-implementing the persistence/dictamen
 * contract inside a mock just to make it useful. Running the real
 * `SolicitudRepository` (real `PlazaRepository` / `DictamenPipeline` /
 * `DictamenContextAssembler`, backed by the shared SQLite test wpdb) is both
 * simpler and the only way to actually prove the row lands with both
 * movements stored. Fixture setup mirrors
 * `Solicitudes\SolicitudRepositoryReasignacionArqueroTest::seedPlazasDeReasignacion()`
 * — goal plaza (titular 111, techo 2.5, the goalkeeper), field plaza
 * (titular 222, techo 3.0, the field titular who moves into goal).
 */
class SolicitudesControllerReasignacionArqueroTest extends TestCase {

    private const SEASON_ID                = 359;
    private const TEAM_ID                   = 100;
    private const OTRO_TEAM_ID              = 200;
    private const GOALKEEPER_PLAYER_ID      = 111;
    private const TITULAR_PLAYER_ID         = 222;
    private const ENTRANTE_CAMPO_PLAYER_ID  = 333;

    private const PLAZA_ID_GENESIS_FECHA = 1;
    private const SOLICITUD_FECHA_ID     = 5;

    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private DictamenPipeline $pipeline;
    private SolicitudRepository $solicitudRepository;
    private JugadorMetricasReader $jugadorMetricasReader;
    private int $plazaArcoId;
    private int $plazaCampoId;
    private int $fixedNow;

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
        $fechaRepository        = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $settings               = new Settings( $wpdb, $this->eventLog );

        $assembler = new DictamenContextAssembler( $this->plazaRepository, $fechaRepository, $settings, $wpdb, $this->eventLog );

        $this->pipeline              = new DictamenPipeline( $assembler, $this->eventLog, null, false, $settings->exencionArcoActiva() );
        $this->solicitudRepository   = new SolicitudRepository( $wpdb, $this->plazaRepository, $this->pipeline, $this->eventLog );
        $this->jugadorMetricasReader = new JugadorMetricasReader( $wpdb, $this->eventLog );

        // Genesis fecha: purely to satisfy openPlaza()'s assertFechaExistsInSeason() guard.
        $this->seedFecha( self::PLAZA_ID_GENESIS_FECHA, '2026-01-01' );

        global $wp_test_position_terms;
        $wp_test_position_terms = [ self::GOALKEEPER_PLAYER_ID => [ 3 ] ]; // term 3 = goalkeeper
        $this->plazaArcoId      = $this->plazaRepository->openPlaza(
            self::SEASON_ID, self::TEAM_ID, self::GOALKEEPER_PLAYER_ID, Puntaje::fromDecimal( 2.5 ), self::PLAZA_ID_GENESIS_FECHA, '2026-01-01 00:00:00'
        );

        $wp_test_position_terms = []; // 222 is an ordinary field player
        $this->plazaCampoId     = $this->plazaRepository->openPlaza(
            self::SEASON_ID, self::TEAM_ID, self::TITULAR_PLAYER_ID, Puntaje::fromDecimal( 3.0 ), self::PLAZA_ID_GENESIS_FECHA, '2026-01-01 00:00:00'
        );

        $this->seedPuntaje( self::TITULAR_PLAYER_ID, 3.0 );
        $this->seedPuntaje( self::ENTRANTE_CAMPO_PLAYER_ID, 2.5 );

        // A FIXED instant, never the real clock — same discipline as
        // SolicitudesControllerTest's own $fixedNow.
        $this->fixedNow = ( new \DateTimeImmutable( '2026-05-20 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $this->seedFecha( self::SOLICITUD_FECHA_ID, gmdate( 'Y-m-d', $this->fixedNow + 3 * DAY_IN_SECONDS ) );
    }

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        // `cambios_settings` is included here (and InitialSchema::up() is
        // re-run below) because
        // test_crear_reasignacion_arquero_con_dictamen_rechazante_sigue_siendo_200()
        // flips `exencion_arco_activa` off via raw SQL — leaving it off would
        // leak into every OTHER test file sharing this same SQLite wpdb test
        // double, exactly like
        // Solicitudes\SolicitudRepositoryReasignacionArqueroTest::tearDown()
        // already documents for the identical mutation.
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta', 'cambios_settings' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wp_test_position_terms = [];
        InitialSchema::up(); // restore settings seeds for any test that runs after this file
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    /**
     * THE central contract this slice wires up: a grouped request created
     * over REST lands `pendiente` with BOTH movements stored, and the
     * response carries the dictamen.
     */
    public function test_crear_reasignacion_arquero_lands_pendiente_con_ambos_movimientos_y_dictamen(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::TITULAR_PLAYER_ID ] );

        $controller = $this->newController( $authorizer );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'                => self::SEASON_ID,
            'team_id'                  => self::TEAM_ID,
            'plaza_id'                 => $this->plazaArcoId,
            'tipo'                     => 'reasignacion_arquero',
            'entrante_player_id'       => self::TITULAR_PLAYER_ID,
            'entrante_campo_player_id' => self::ENTRANTE_CAMPO_PLAYER_ID,
            'fecha_id'                 => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( EstadoSolicitud::PENDIENTE, $data['estado'] );
        $this->assertArrayHasKey( 'dictamen', $data );
        $this->assertTrue( $data['dictamen']['procede'] );
        $this->assertSame( [], $data['dictamen']['motivos'] );

        $row = $this->solicitudRepository->findSolicitud( $data['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 'reasignacion_arquero', $row['tipo'] );
        $this->assertSame( $this->plazaArcoId, (int) $row['plaza_id'] );
        $this->assertSame( self::TITULAR_PLAYER_ID, (int) $row['entrante_player_id'] );
        $this->assertSame( self::GOALKEEPER_PLAYER_ID, (int) $row['saliente_player_id'] );
        $this->assertSame( $this->plazaCampoId, (int) $row['plaza_campo_id'], 'plaza_campo_id must be DERIVED, matching the titular\'s own field plaza.' );
        $this->assertSame( self::ENTRANTE_CAMPO_PLAYER_ID, (int) $row['entrante_campo_player_id'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
    }

    /**
     * A rejecting dictamen is STILL a successful 200 — with the exemption
     * off, the titular structurally occupying his own field plaza makes
     * movement 1 fail `EntranteDisponible`, exactly like the domain-layer
     * suite's own "rechazado sin exencion" test.
     */
    public function test_crear_reasignacion_arquero_con_dictamen_rechazante_sigue_siendo_200(): void {
        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}cambios_settings SET setting_value = '0' WHERE setting_key = 'exencion_arco_activa'" );

        $settings  = new Settings( $wpdb, $this->eventLog );
        $assembler = new DictamenContextAssembler( $this->plazaRepository, new FechaRepository( $wpdb, new InMemoryEventLog() ), $settings, $wpdb, $this->eventLog );
        $pipelineSinExencion = new DictamenPipeline( $assembler, $this->eventLog, null, false, $settings->exencionArcoActiva() );
        $repoSinExencion     = new SolicitudRepository( $wpdb, $this->plazaRepository, $pipelineSinExencion, $this->eventLog );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::TITULAR_PLAYER_ID ] );

        $controller = new SolicitudesController(
            $authorizer,
            $repoSinExencion,
            $pipelineSinExencion,
            $this->eventLog,
            $this->plazaRepository,
            $this->jugadorMetricasReader,
            fn (): int => $this->fixedNow
        );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'                => self::SEASON_ID,
            'team_id'                  => self::TEAM_ID,
            'plaza_id'                 => $this->plazaArcoId,
            'tipo'                     => 'reasignacion_arquero',
            'entrante_player_id'       => self::TITULAR_PLAYER_ID,
            'entrante_campo_player_id' => self::ENTRANTE_CAMPO_PLAYER_ID,
            'fecha_id'                 => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertFalse( $data['dictamen']['procede'] );
        $this->assertNotEmpty( $data['dictamen']['motivos'] );

        $codigos = array_column( $data['dictamen']['motivos'], 'codigo' );
        $this->assertContains( 'entrante_ocupa_otra_plaza_vigente', $codigos );

        // Attributed per movement, as 0.1.16 already does — see
        // Dictamen\Motivo::conMovimiento() / DictamenPipeline::evaluateGrupo().
        // `movimiento` lives inside each motivo's own `datos`, never as a
        // top-level key — see Motivo::conMovimiento()'s own docblock.
        $movimientos = array_map(
            static fn ( array $motivo ): ?string => $motivo['datos']['movimiento'] ?? null,
            $data['dictamen']['motivos']
        );
        $this->assertContains( 'arco', $movimientos );
    }

    // -------------------------------------------------------------------------
    // 400 — missing/invalid fields, documented order
    // -------------------------------------------------------------------------

    public function test_crear_reasignacion_arquero_sin_plaza_id_retorna_400_campos_invalidos(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::TITULAR_PLAYER_ID ] );

        $controller = $this->newController( $authorizer );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'                => self::SEASON_ID,
            'team_id'                  => self::TEAM_ID,
            'tipo'                     => 'reasignacion_arquero',
            'entrante_player_id'       => self::TITULAR_PLAYER_ID,
            'entrante_campo_player_id' => self::ENTRANTE_CAMPO_PLAYER_ID,
            'fecha_id'                 => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'campos_invalidos', $response->get_data()['code'] );
    }

    public function test_crear_reasignacion_arquero_sin_titular_retorna_400_titular_requerido(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::TITULAR_PLAYER_ID ] );

        $controller = $this->newController( $authorizer );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'                => self::SEASON_ID,
            'team_id'                  => self::TEAM_ID,
            'plaza_id'                 => $this->plazaArcoId,
            'tipo'                     => 'reasignacion_arquero',
            'entrante_campo_player_id' => self::ENTRANTE_CAMPO_PLAYER_ID,
            'fecha_id'                 => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'titular_requerido', $response->get_data()['code'] );
    }

    public function test_crear_reasignacion_arquero_sin_entrante_campo_retorna_400_entrante_campo_requerido(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::TITULAR_PLAYER_ID ] );

        $controller = $this->newController( $authorizer );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'          => self::SEASON_ID,
            'team_id'            => self::TEAM_ID,
            'plaza_id'           => $this->plazaArcoId,
            'tipo'               => 'reasignacion_arquero',
            'entrante_player_id' => self::TITULAR_PLAYER_ID,
            'fecha_id'           => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'entrante_campo_requerido', $response->get_data()['code'] );
    }

    public function test_crear_tipo_desconocido_retorna_400_tipo_invalido(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::TITULAR_PLAYER_ID ] );

        $controller = $this->newController( $authorizer );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => $this->plazaArcoId,
            'tipo'      => 'tipo-que-no-existe',
            'fecha_id'  => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'tipo_invalido', $response->get_data()['code'] );
    }

    // -------------------------------------------------------------------------
    // Prerequisite 2 — ownership proven BEFORE the structural guards run
    // -------------------------------------------------------------------------

    /**
     * A captain of team A cannot create a grouped request naming team B —
     * and the refusal must be the AUTHORIZATION one (403 `no_capitan`), never
     * `assertPlazasDeReasignacionArquero()`'s own \InvalidArgumentException
     * surfacing as a generic 500. Asserted with a repository double that
     * fails the test if `crearReasignacionArquero()` is ever called — proof
     * that authorization runs BEFORE the structural guard, not merely
     * before some of it.
     */
    public function test_crear_reasignacion_arquero_de_otro_equipo_es_rechazado_por_autorizacion_antes_del_guard_estructural(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new NotCaptainException() );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'crearReasignacionArquero' );

        $controller = new SolicitudesController(
            $authorizer,
            $repo,
            $this->pipeline,
            $this->eventLog,
            $this->plazaRepository,
            $this->jugadorMetricasReader,
            fn (): int => $this->fixedNow
        );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'                => self::SEASON_ID,
            'team_id'                  => self::OTRO_TEAM_ID,
            'plaza_id'                 => $this->plazaArcoId,
            'tipo'                     => 'reasignacion_arquero',
            'entrante_player_id'       => self::TITULAR_PLAYER_ID,
            'entrante_campo_player_id' => self::ENTRANTE_CAMPO_PLAYER_ID,
            'fecha_id'                 => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'no_capitan', $response->get_data()['code'] );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function newController( CapitanAuthorizer $authorizer ): SolicitudesController {
        return new SolicitudesController(
            $authorizer,
            $this->solicitudRepository,
            $this->pipeline,
            $this->eventLog,
            $this->plazaRepository,
            $this->jugadorMetricasReader,
            fn (): int => $this->fixedNow
        );
    }

    private function seedFecha( int $fechaId, string $playDate ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => self::SEASON_ID,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => 'programada',
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

    /** @param array<string, mixed> $params */
    private function requestConToken( string $token, array $params ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer ' . $token );

        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }

        return $request;
    }
}
