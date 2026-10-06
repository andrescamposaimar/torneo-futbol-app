<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Auth\Exception\TokenExpiredException;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Capitania\Exception\NotCaptainException;
use EntreRedes\Cambios\Capitania\Exception\SessionRevokedException;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
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
 * Tests for Rest\SolicitudesController.
 *
 * *** WHY DictamenPipeline IS REAL, NOT A DOUBLE ***
 * DictamenPipeline and DictamenContextAssembler are both `final` — PHPUnit
 * cannot generate a double for either. Rather than weaken the controller's
 * design just to make it mockable, this suite runs the REAL pipeline
 * (real DictamenContextAssembler, real PlazaRepository/FechaRepository/
 * Settings, backed by the shared SQLite test wpdb) — the same style as
 * DictamenPipelineTest / SolicitudRepositoryTest. CapitanAuthorizer and
 * SolicitudRepository ARE mocked (neither is final): this is what lets the
 * authorization-failure and unexpected-failure tests below force an exact,
 * deterministic outcome without needing a real JWT or a real persistence
 * failure.
 *
 * The seeded plaza/fecha fixture is fully favorable by construction (mirrors
 * DictamenPipelineTest's own fixtures) — the "dictamen no procede" test below
 * makes exactly ONE rule fail (EntranteNoEsElSaliente, by naming the vigent
 * occupant as their own entrante) rather than re-deriving a whole new
 * scenario, so it stays obviously connected to the favorable baseline.
 *
 * `fecha_id` for the solicitud itself is seeded relative to `$fixedNow` — a
 * FIXED instant injected into the controller via its `$clockFn` constructor
 * argument (see `newController()`), never the real clock. An earlier
 * version of this suite computed the fixture relative to `time()` because
 * the controller had no way to accept an injected "now" at all; that made
 * the suite's outcome depend on whatever day it happened to run, which is
 * exactly the flakiness a fixed clock exists to remove. Seeding a play_date
 * comfortably inside the default plazo window relative to `$fixedNow` is
 * what keeps SolicitudEnPlazo favorable, deterministically, forever.
 */
class SolicitudesControllerTest extends TestCase {

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;
    private const PLAYER_ID = 777;

    private const PLAZA_ID_GENESIS_FECHA = 1;
    private const SOLICITUD_FECHA_ID     = 5;

    private InMemoryEventLog $eventLog;
    private DictamenPipeline $pipeline;
    private PlazaRepository $plazaRepository;
    private JugadorMetricasReader $jugadorMetricasReader;
    private int $plazaId;

    /**
     * A fixed instant, injected into every controller this suite builds via
     * newController() — see that method's docblock for why the controller
     * takes a `$clockFn` at all. Replaces a fixture that used to compute the
     * solicitud's fecha relative to the REAL clock
     * (`gmdate(..., time() + 3 * DAY_IN_SECONDS)`), which meant the test's
     * outcome quietly depended on whatever day it happened to run.
     */
    private int $fixedNow;

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

        $this->eventLog = new InMemoryEventLog();

        $plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $fechaRepository = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $settings        = new Settings( $wpdb, $this->eventLog );

        $assembler = new DictamenContextAssembler( $plazaRepository, $fechaRepository, $settings, $wpdb, $this->eventLog );

        $this->pipeline              = new DictamenPipeline( $assembler, $this->eventLog );
        $this->plazaRepository       = $plazaRepository;
        $this->jugadorMetricasReader = new JugadorMetricasReader( $wpdb, $this->eventLog );

        // Genesis fecha: any past date, purely to satisfy openPlaza()'s
        // assertFechaExistsInSeason() guard — irrelevant to the plazo window.
        $this->seedFecha( self::PLAZA_ID_GENESIS_FECHA, '2026-01-01' );

        // A FIXED instant, never the real clock — see $fixedNow's own
        // docblock. Any value works as long as the seeded fecha below stays
        // inside the default plazo window relative to it.
        $this->fixedNow = ( new \DateTimeImmutable( '2026-05-20 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        // The solicitud's OWN fecha: a play_date placed comfortably inside
        // the default plazo window (apertura_solicitudes..cierre_solicitudes
        // = play_date-6d..play_date-2d, see InitialSchema::SEED_DEFAULTS)
        // around $fixedNow — see class docblock.
        $this->seedFecha( self::SOLICITUD_FECHA_ID, gmdate( 'Y-m-d', $this->fixedNow + 3 * DAY_IN_SECONDS ) );

        $this->plazaId = $plazaRepository->openPlaza(
            self::SEASON_ID,
            self::TEAM_ID,
            self::PLAYER_ID,
            Puntaje::fromDecimal( 3.0 ),
            self::PLAZA_ID_GENESIS_FECHA,
            '2026-01-01 00:00:00'
        );

        $this->seedPuntaje( 888, 2.5 );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
    }

    // -------------------------------------------------------------------------
    // POST /cambios/solicitudes
    // -------------------------------------------------------------------------

    public function test_crear_happy_path_returns_200_with_a_favorable_dictamen(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->once() )
            ->method( 'authorize' )
            ->with( 'a-valid-jwt', self::SEASON_ID, self::TEAM_ID, $this->isType( 'int' ) )
            ->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->once() )
            ->method( 'crear' )
            ->with(
                $this->callback( function ( SolicitudDeCambio $s ): bool {
                    return self::SEASON_ID === $s->seasonId()
                        && self::TEAM_ID === $s->teamId()
                        && $this->plazaId === $s->plazaId()
                        && 888 === $s->entrantePlayerId()
                        && self::SOLICITUD_FECHA_ID === $s->fechaId()
                        && $s->isSustitucion();
                } ),
                self::PLAYER_ID,
                $this->callback( static fn ( $d ) => $d->procede() ),
                $this->isType( 'string' )
            )
            ->willReturn( 42 );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'          => self::SEASON_ID,
            'team_id'             => self::TEAM_ID,
            'plaza_id'            => $this->plazaId,
            'tipo'                => 'sustitucion',
            'entrante_player_id'  => 888,
            'fecha_id'            => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 42, $data['id'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $data['estado'] );
        $this->assertTrue( $data['dictamen']['procede'] );
        $this->assertSame( [], $data['dictamen']['motivos'] );
    }

    /**
     * THE central matiz this slice exists to enforce: a dictamen that does
     * NOT procede() is a normal, successful 200 — never a 4xx/5xx — carrying
     * the motivos the captain needs. Forces exactly ONE rule
     * (EntranteNoEsElSaliente) to object by naming the plaza's own vigent
     * occupant as the entrante.
     */
    public function test_crear_with_a_non_favorable_dictamen_is_still_a_200(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->once() )->method( 'crear' )->willReturn( 44 );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'          => self::SEASON_ID,
            'team_id'             => self::TEAM_ID,
            'plaza_id'            => $this->plazaId,
            'tipo'                => 'sustitucion',
            'entrante_player_id'  => self::PLAYER_ID, // the vigent occupant itself
            'fecha_id'            => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertFalse( $data['dictamen']['procede'] );
        $this->assertNotEmpty( $data['dictamen']['motivos'] );
    }

    public function test_crear_regreso_does_not_require_entrante_player_id(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->once() )
            ->method( 'crear' )
            ->with( $this->callback( static fn ( SolicitudDeCambio $s ): bool => $s->isRegreso() && null === $s->entrantePlayerId() ) )
            ->willReturn( 43 );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => $this->plazaId,
            'tipo'      => 'regreso',
            'fecha_id'  => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_crear_missing_season_or_team_returns_400_without_authorizing(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'crear' );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [ 'team_id' => self::TEAM_ID ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'campos_invalidos', $response->get_data()['code'] );
    }

    public function test_crear_invalid_tipo_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'crear' );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => $this->plazaId,
            'tipo'      => 'algo-inventado',
            'fecha_id'  => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'tipo_invalido', $response->get_data()['code'] );
    }

    public function test_crear_sustitucion_without_entrante_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'crear' );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => $this->plazaId,
            'tipo'      => 'sustitucion',
            'fecha_id'  => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'entrante_requerido', $response->get_data()['code'] );
    }

    /**
     * THE WIRING GUARANTEE (slice 4d task brief, point 4/5), UPDATED for FIX
     * 1 of the slice 5 task brief: the three AuthorizationDeniedException
     * subtypes no longer produce the SAME body — see
     * Rest\HandlesCapitanAuthorization::respuestaNoAutorizada()'s own
     * docblock for the exact status/code mapping this now asserts — but the
     * repository is STILL never touched for any of them, and the
     * human-readable `message` STILL stays the one generic text regardless
     * of which subtype fired. Asserted with a repository double that FAILS
     * the test if any of its methods is called.
     *
     * @dataProvider authorizationFailureProvider
     */
    public function test_crear_returns_the_precise_status_and_code_for_every_authorization_failure_without_touching_the_repository(
        \Throwable $exception,
        int $expectedStatus,
        string $expectedCode
    ): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( $exception );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'crear' );
        $repo->expects( $this->never() )->method( 'listByEquipo' );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->crear( $this->requestConToken( 'whatever', [
            'season_id'          => self::SEASON_ID,
            'team_id'             => self::TEAM_ID,
            'plaza_id'            => $this->plazaId,
            'tipo'                => 'sustitucion',
            'entrante_player_id'  => 888,
            'fecha_id'            => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( $expectedStatus, $response->get_status() );
        $this->assertSame(
            [
                'code'    => $expectedCode,
                'message' => 'No estás autorizado para realizar esta acción en este equipo y temporada.',
                'data'    => [ 'status' => $expectedStatus ],
            ],
            $response->get_data()
        );

        $this->assertTrue( $this->eventLog->has( 'rest.autorizacion_denegada' ) );
        $this->assertSame( get_class( $exception ), $this->eventLog->last()['contexto']['excepcion'] );
    }

    /** @return array<string, array{0: \Throwable, 1: int, 2: string}> */
    public static function authorizationFailureProvider(): array {
        return [
            // No wrapped TokenVerificationException — the same shape
            // CapitanAuthorizer::verifyIdentity() produces for a missing or
            // malformed Authorization header — collapses into 'token_invalid',
            // never 'token_expired'.
            'invalid token'   => [ new InvalidTokenException(), 401, 'token_invalid' ],
            'expired token'   => [ new InvalidTokenException( new TokenExpiredException() ), 401, 'token_expired' ],
            'revoked session' => [ new SessionRevokedException(), 401, 'session_revoked' ],
            'not captain'     => [ new NotCaptainException(), 403, 'no_capitan' ],
        ];
    }

    public function test_crear_unexpected_exception_returns_generic_500_and_logs_identifiers(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'crear' );

        $controller = $this->newController( $authorizer, $repo );

        $plazaInexistente = 999999;

        $response = $controller->crear( $this->requestConToken( 'a-valid-jwt', [
            'season_id'          => self::SEASON_ID,
            'team_id'             => self::TEAM_ID,
            'plaza_id'            => $plazaInexistente,
            'tipo'                => 'sustitucion',
            'entrante_player_id'  => 888,
            'fecha_id'            => self::SOLICITUD_FECHA_ID,
        ] ) );

        $this->assertSame( 500, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'error_interno', $data['code'] );
        $this->assertStringNotContainsString( (string) $plazaInexistente, $data['message'] );
        $this->assertStringNotContainsString( 'RuntimeException', json_encode( $data ) );

        $this->assertTrue( $this->eventLog->has( 'rest.solicitud_crear_fallida' ) );
        $logged = $this->eventLog->last()['contexto'];
        $this->assertSame( self::SEASON_ID, $logged['season_id'] );
        $this->assertSame( self::TEAM_ID, $logged['team_id'] );
        $this->assertSame( $plazaInexistente, $logged['plaza_id'] );
        $this->assertSame( self::SOLICITUD_FECHA_ID, $logged['fecha_id'] );
        $this->assertSame( \RuntimeException::class, $logged['excepcion'] );
        $this->assertStringContainsString( (string) $plazaInexistente, $logged['mensaje'] );
    }

    // -------------------------------------------------------------------------
    // GET /cambios/solicitudes
    // -------------------------------------------------------------------------

    public function test_listar_happy_path_returns_shaped_rows(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $snapshotJson = json_encode( [
            'procede'     => false,
            'motivos'     => [ [ 'codigo' => 'x', 'mensaje' => 'y', 'datos' => [] ] ],
            'evaluado_at' => '2026-01-01 00:00:00',
        ] );

        $row = [
            'id'                 => 7,
            'plaza_id'           => $this->plazaId,
            'tipo'               => 'sustitucion',
            'entrante_player_id' => 888,
            'saliente_player_id' => self::PLAYER_ID,
            'fecha_id'           => self::SOLICITUD_FECHA_ID,
            'estado'             => 'pendiente',
            'solicitada_at'      => '2026-01-03 12:00:00',
            'resuelta_at'        => null,
            'nota'               => null,
            'dictamen_original'  => $snapshotJson,
        ];

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->once() )
            ->method( 'listByEquipo' )
            ->with( self::SEASON_ID, self::TEAM_ID )
            ->willReturn( [ $row ] );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data['solicitudes'] );
        $this->assertSame( 7, $data['solicitudes'][0]['id'] );
        $this->assertSame( 888, $data['solicitudes'][0]['entrante_player_id'] );
        $this->assertFalse( $data['solicitudes'][0]['dictamen']['procede'] );
    }

    // -------------------------------------------------------------------------
    // GET /cambios/solicitudes — sale/entra
    // -------------------------------------------------------------------------

    /**
     * `sustitucion`: `sale` is the stored `saliente_player_id`, `entra` is
     * the stored `entrante_player_id` — both resolved to a name and a
     * puntaje.
     */
    public function test_listar_resuelve_sale_y_entra_para_sustitucion(): void {
        global $wp_test_post_titles;
        $wp_test_post_titles = [ self::PLAYER_ID => 'Campos, Andres', 888 => 'Grigorjew, Gerardo' ];

        $this->seedPuntaje( self::PLAYER_ID, 4.5 );
        // 888 already seeded with 2.5 in setUp().

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $row = $this->baseRow( [
            'id'                 => 7,
            'tipo'               => 'sustitucion',
            'entrante_player_id' => 888,
            'saliente_player_id' => self::PLAYER_ID,
        ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'listByEquipo' )->willReturn( [ $row ] );

        $controller = $this->newController( $authorizer, $repo );
        $response   = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $data      = $response->get_data();
        $solicitud = $data['solicitudes'][0];

        $this->assertSame( [ 'player_id' => self::PLAYER_ID, 'nombre' => 'Campos, Andres', 'puntaje' => 4.5 ], $solicitud['sale'] );
        $this->assertSame( [ 'player_id' => 888, 'nombre' => 'Grigorjew, Gerardo', 'puntaje' => 2.5 ], $solicitud['entra'] );

        $wp_test_post_titles = [];
    }

    /**
     * `regreso`: `entrante_player_id` is always NULL — `entra` must instead
     * be the plaza's PERMANENT titular (`cambios_plaza.titular_player_id`,
     * self::PLAYER_ID here — see setUp()'s `openPlaza()` call), resolved via
     * `PlazaRepository::listPlazasByEquipo()`, never a stored
     * `entrante_player_id`. `sale` stays the stored `saliente_player_id` —
     * the suplente the titular would displace.
     */
    public function test_listar_para_regreso_entra_es_el_titular_y_sale_el_ocupante_vigente(): void {
        global $wp_test_post_titles;
        $wp_test_post_titles = [ self::PLAYER_ID => 'Campos, Andres', 999 => 'Pérez, Martín' ];

        $this->seedPuntaje( self::PLAYER_ID, 4.5 );
        $this->seedPuntaje( 999, 3.0 );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $row = $this->baseRow( [
            'id'                 => 9,
            'tipo'               => 'regreso',
            'entrante_player_id' => null,
            'saliente_player_id' => 999,
        ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'listByEquipo' )->willReturn( [ $row ] );

        $controller = $this->newController( $authorizer, $repo );
        $response   = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $solicitud = $response->get_data()['solicitudes'][0];

        $this->assertSame( [ 'player_id' => 999, 'nombre' => 'Pérez, Martín', 'puntaje' => 3.0 ], $solicitud['sale'] );
        $this->assertSame( [ 'player_id' => self::PLAYER_ID, 'nombre' => 'Campos, Andres', 'puntaje' => 4.5 ], $solicitud['entra'] );

        $wp_test_post_titles = [];
    }

    /**
     * A `saliente_player_id` of NULL (a row that predates the 0.1.11 column)
     * must degrade `sale` to an ENTIRELY null object — never a guessed id,
     * never a fabricated name or puntaje.
     */
    public function test_listar_degrada_sale_a_null_cuando_no_hay_saliente_registrado(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $row = $this->baseRow( [
            'id'                 => 11,
            'tipo'               => 'sustitucion',
            'entrante_player_id' => 888,
            'saliente_player_id' => null,
        ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'listByEquipo' )->willReturn( [ $row ] );

        $controller = $this->newController( $authorizer, $repo );
        $response   = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $solicitud = $response->get_data()['solicitudes'][0];

        $this->assertSame( [ 'player_id' => null, 'nombre' => null, 'puntaje' => null ], $solicitud['sale'] );
        $this->assertNotNull( $solicitud['entra']['player_id'] );
    }

    /**
     * A player with no resolvable puntaje (no `sp_metrics` row at all) must
     * still get a name — the degrade is scoped to `puntaje` alone, never
     * spreading to `nombre` — see `shapeLado()`'s own docblock.
     */
    public function test_listar_degrada_solo_el_puntaje_cuando_no_es_resoluble_el_nombre_se_mantiene(): void {
        global $wp_test_post_titles;
        $wp_test_post_titles = [ 888 => 'Sin Puntaje, Jugador' ];

        // Deliberately NOT seeding sp_metrics for 888 this time — the
        // setUp() seed is for a DIFFERENT test's isolation, so this test
        // clears postmeta for player 888 specifically by never writing it
        // (setUp() seeds a row for 888, so clear it first).
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}postmeta WHERE post_id = %d", 888 ) );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $row = $this->baseRow( [
            'id'                 => 13,
            'tipo'               => 'sustitucion',
            'entrante_player_id' => 888,
            'saliente_player_id' => self::PLAYER_ID,
        ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'listByEquipo' )->willReturn( [ $row ] );

        $controller = $this->newController( $authorizer, $repo );
        $response   = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $solicitud = $response->get_data()['solicitudes'][0];

        $this->assertSame( 888, $solicitud['entra']['player_id'] );
        $this->assertSame( 'Sin Puntaje, Jugador', $solicitud['entra']['nombre'] );
        $this->assertNull( $solicitud['entra']['puntaje'] );

        $wp_test_post_titles = [];
    }

    /**
     * Names and puntajes for a MULTI-ROW list must resolve in ONE batched
     * call per meta_key (`JugadorMetricasReader::resolveMuchos()`), never
     * one query per row/player — asserted here by counting the actual
     * `postmeta` SELECTs a wpdb proxy observes, regardless of how many
     * solicitud rows or distinct players are involved.
     */
    public function test_listar_resuelve_nombres_y_puntajes_en_una_sola_llamada_batcheada_para_varias_filas(): void {
        global $wp_test_post_titles;
        $wp_test_post_titles = [ self::PLAYER_ID => 'Campos, Andres', 888 => 'Grigorjew, Gerardo', 999 => 'Pérez, Martín' ];
        $this->seedPuntaje( self::PLAYER_ID, 4.5 );
        $this->seedPuntaje( 999, 3.0 );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $rowA = $this->baseRow( [ 'id' => 21, 'tipo' => 'sustitucion', 'entrante_player_id' => 888, 'saliente_player_id' => self::PLAYER_ID ] );
        $rowB = $this->baseRow( [ 'id' => 22, 'tipo' => 'sustitucion', 'entrante_player_id' => 999, 'saliente_player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'listByEquipo' )->willReturn( [ $rowA, $rowB ] );

        global $wpdb;
        $countingWpdb = $this->wpdbCountingPostmetaSelects( $wpdb );

        // plazaRepository stays the REAL, non-counting one — listPlazasByEquipo()
        // reads cambios_plaza, never postmeta, and this test only cares about
        // postmeta-level batching.
        $jugadorMetricasReaderConContador = new JugadorMetricasReader( $countingWpdb, $this->eventLog );

        $controller = new SolicitudesController(
            $authorizer,
            $repo,
            $this->pipeline,
            $this->eventLog,
            $this->plazaRepository,
            $jugadorMetricasReaderConContador,
            fn (): int => $this->fixedNow
        );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );

        // Exactly 2 — one per meta_key (sp_metrics, caracter) — regardless of
        // 2 rows sharing 3 distinct player ids across sale/entra.
        $this->assertSame(
            2,
            $countingWpdb->countPostmetaSelects(),
            'Puntaje/caracter resolution must be ONE batched call per meta_key, never one per row.'
        );

        $wp_test_post_titles = [];
    }

    /**
     * @param \wpdb $real A live wpdb sharing THIS test's SQLite connection.
     * @return \wpdb A proxy whose own `countPostmetaSelects()` reports how
     *         many `postmeta` SELECTs it has executed so far.
     */
    private function wpdbCountingPostmetaSelects( \wpdb $real ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix ) extends \wpdb {
            private int $postmetaSelectCount = 0;

            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, 'FROM ' . $this->prefix . 'postmeta' ) ) {
                    $this->postmetaSelectCount++;
                }

                return parent::get_results( $sql, $output );
            }

            public function countPostmetaSelects(): int {
                return $this->postmetaSelectCount;
            }
        };
    }

    /**
     * A solicitud row with every field `shapeSolicitudRow()` reads, with
     * overrides applied on top — keeps every sale/entra test above focused
     * on what it actually varies instead of repeating the full shape.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseRow( array $overrides ): array {
        return array_merge( [
            'id'                 => 1,
            'plaza_id'           => $this->plazaId,
            'tipo'               => 'sustitucion',
            'entrante_player_id' => 888,
            'saliente_player_id' => self::PLAYER_ID,
            'fecha_id'           => self::SOLICITUD_FECHA_ID,
            'estado'             => 'pendiente',
            'solicitada_at'      => '2026-01-03 12:00:00',
            'resuelta_at'        => null,
            'nota'               => null,
            'dictamen_original'  => json_encode( [ 'procede' => true, 'motivos' => [], 'evaluado_at' => '2026-01-01 00:00:00' ] ),
        ], $overrides );
    }

    public function test_listar_missing_season_or_team_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'listByEquipo' );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [] ) );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_listar_returns_403_no_capitan_without_touching_the_repository(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new NotCaptainException() );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->expects( $this->never() )->method( 'listByEquipo' );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'no_capitan', $response->get_data()['code'] );
    }

    public function test_listar_unexpected_exception_returns_generic_500_and_logs_identifiers(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'listByEquipo' )->willThrowException( new \RuntimeException( 'query rota' ) );

        $controller = $this->newController( $authorizer, $repo );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertTrue( $this->eventLog->has( 'rest.solicitudes_listar_fallida' ) );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Builds the controller under test with $fixedNow injected as its clock
     * — every test in this suite goes through here rather than constructing
     * SolicitudesController directly, so the fixed instant can never
     * accidentally diverge from the one the fecha fixture above was seeded
     * against.
     */
    private function newController( CapitanAuthorizer $authorizer, SolicitudRepository $repo ): SolicitudesController {
        return new SolicitudesController(
            $authorizer,
            $repo,
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
