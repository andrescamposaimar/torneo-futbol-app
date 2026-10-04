<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Capitania\Exception\SessionRevokedException;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Rest\FechaController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Rest\FechaController — FIX 2 of the slice 5 task brief:
 * `GET /entre-redes/v1/cambios/fecha-abierta`, the endpoint the "Pedir
 * cambio" screen needs to learn WHICH fecha to request a change for, since
 * `POST /cambios/solicitudes` requires a `fecha_id` no other route exposed.
 *
 * *** WHY Settings IS REAL, NEVER MOCKED ***
 * Settings is a thin, final-adjacent accessor over `cambios_settings`, whose
 * defaults (InitialSchema::SEED_DEFAULTS) are what this suite's plazo maths
 * below are computed FROM — mocking it would mean re-deriving those same
 * default offsets by hand in the mock's setup, drifting from the migration's
 * own seed the moment either one changes. The real class, backed by the
 * shared SQLite test wpdb (auto-seeded by InitialSchema::up()), is simpler
 * and cannot drift.
 *
 * *** THE PLAZO MATHS THIS SUITE'S FIXTURES DEPEND ON ***
 * Default offsets (America/Argentina/Buenos_Aires, UTC-3 year-round — no
 * DST): apertura_solicitudes = play_date-6d 00:00:00; cierre_regresos =
 * play_date-4d 23:59:59; cierre_solicitudes = play_date-2d 23:59:59;
 * publicacion = play_date-1d 00:00:00 — all civil, in BA time. Converted to
 * UTC (+3h), for play_date '2026-01-10':
 *   apertura_solicitudes = 2026-01-04 03:00:00 UTC
 *   cierre_regresos      = 2026-01-07 02:59:59 UTC
 *   cierre_solicitudes   = 2026-01-09 02:59:59 UTC
 *   publicacion          = 2026-01-09 03:00:00 UTC
 */
class FechaControllerTest extends TestCase {

    private const SEASON_ID = 359;

    private InMemoryEventLog $eventLog;
    private FechaRepository $fechaRepository;
    private Settings $settings;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        $this->eventLog        = new InMemoryEventLog();
        $this->fechaRepository = new FechaRepository( $wpdb, $this->eventLog );
        $this->settings         = new Settings( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_fecha" );
    }

    // -------------------------------------------------------------------------
    // GET /cambios/fecha-abierta — happy paths
    // -------------------------------------------------------------------------

    public function test_returns_the_earliest_unresolved_fecha_skipping_resolved_ones_before_it(): void {
        // Two resolved fechas (jugada, dirimida) precede the first
        // unresolved one — the endpoint must skip both, never returning the
        // earliest ROW, only the earliest UNRESOLVED one.
        $this->seedFecha( 1, 1, '2025-12-01', 'jugada' );
        $this->seedFecha( 2, 2, '2025-12-08', 'dirimida' );
        $this->seedFecha( 3, 3, '2026-01-10', 'programada' );
        $this->seedFecha( 4, 4, '2026-01-17', 'programada' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->once() )
            ->method( 'verifyIdentity' )
            ->willReturn( [ 'player_id' => 777 ] );

        $now = ( new \DateTimeImmutable( '2026-01-05 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $controller = $this->newController( $authorizer, $now );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNotNull( $data['fecha'] );
        $this->assertSame( 3, $data['fecha']['fecha_id'] );
        $this->assertSame( 3, $data['fecha']['numero_en_torneo'] );
        $this->assertSame( 'Apertura', $data['fecha']['torneo'] );
        $this->assertSame( '2026-01-10', $data['fecha']['play_date'] );
        $this->assertSame(
            [
                'apertura_solicitudes' => '2026-01-04 03:00:00',
                'cierre_regresos'      => '2026-01-07 02:59:59',
                'cierre_solicitudes'   => '2026-01-09 02:59:59',
                'publicacion'          => '2026-01-09 03:00:00',
            ],
            $data['fecha']['plazos_utc']
        );
        // $now (2026-01-05 12:00:00 UTC) is inside BOTH windows.
        $this->assertSame( 'abierta', $data['fecha']['ventanas']['regreso'] );
        $this->assertSame( 'abierta', $data['fecha']['ventanas']['sustitucion'] );
    }

    /**
     * THE regression case this slice's task brief calls out explicitly: a
     * `$now` strictly before `apertura_solicitudes` must read as `'antes'`,
     * NEVER `'cerrada'` — a plain boolean collapsed both into the same
     * `false` (see FechaController's own docblock, "THREE STATES, NOT TWO").
     */
    public function test_both_windows_read_antes_strictly_before_apertura_solicitudes(): void {
        $this->seedFecha( 3, 1, '2026-01-10', 'programada' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        // Strictly before apertura_solicitudes (2026-01-04 03:00:00 UTC).
        $now = ( new \DateTimeImmutable( '2026-01-04 02:59:58', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $controller = $this->newController( $authorizer, $now );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $ventanas = $response->get_data()['fecha']['ventanas'];
        $this->assertSame( 'antes', $ventanas['regreso'] );
        $this->assertSame( 'antes', $ventanas['sustitucion'] );
    }

    /**
     * THE case this slice's task brief calls out explicitly: regreso closed,
     * sustitucion still open — the two windows must be computed
     * INDEPENDENTLY, never one derived from the other.
     */
    public function test_regreso_closed_but_sustitucion_still_open_are_computed_independently(): void {
        $this->seedFecha( 3, 1, '2026-01-10', 'programada' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        // Past cierre_regresos (2026-01-07 02:59:59 UTC), still before
        // cierre_solicitudes (2026-01-09 02:59:59 UTC).
        $now = ( new \DateTimeImmutable( '2026-01-08 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $controller = $this->newController( $authorizer, $now );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $ventanas = $response->get_data()['fecha']['ventanas'];
        $this->assertSame( 'cerrada', $ventanas['regreso'] );
        $this->assertSame( 'abierta', $ventanas['sustitucion'] );
    }

    public function test_both_windows_cerrada_once_past_cierre_solicitudes(): void {
        $this->seedFecha( 3, 1, '2026-01-10', 'programada' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        // Past cierre_solicitudes (2026-01-09 02:59:59 UTC).
        $now = ( new \DateTimeImmutable( '2026-01-10 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $controller = $this->newController( $authorizer, $now );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $ventanas = $response->get_data()['fecha']['ventanas'];
        $this->assertSame( 'cerrada', $ventanas['regreso'] );
        $this->assertSame( 'cerrada', $ventanas['sustitucion'] );
    }

    public function test_returns_fecha_null_when_every_fecha_of_the_season_is_resolved(): void {
        $this->seedFecha( 1, 1, '2025-12-01', 'jugada' );
        $this->seedFecha( 2, 2, '2025-12-08', 'dirimida' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        $controller = $this->newController( $authorizer, time() );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'fecha' => null ], $response->get_data() );
    }

    public function test_returns_fecha_null_when_the_season_has_no_fechas_at_all(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        $controller = $this->newController( $authorizer, time() );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'fecha' => null ], $response->get_data() );
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_missing_season_id_returns_400_without_touching_the_authorizer(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'verifyIdentity' );

        $controller = $this->newController( $authorizer, time() );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [] ) );

        $this->assertSame( 400, $response->get_status() );
    }

    // -------------------------------------------------------------------------
    // Authorization failures — identity-only, never team-scoped
    // -------------------------------------------------------------------------

    public function test_calls_verifyIdentity_never_authorize(): void {
        $this->seedFecha( 3, 1, '2026-01-10', 'programada' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->once() )->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $now        = ( new \DateTimeImmutable( '2026-01-05 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $controller = $this->newController( $authorizer, $now );

        $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [ 'season_id' => self::SEASON_ID ] ) );
    }

    public function test_invalid_token_returns_401_token_invalid(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willThrowException( new InvalidTokenException() );

        $controller = $this->newController( $authorizer, time() );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-bad-jwt', [ 'season_id' => self::SEASON_ID ] ) );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'token_invalid', $response->get_data()['code'] );
        $this->assertTrue( $this->eventLog->has( 'rest.autorizacion_denegada' ) );
    }

    public function test_revoked_session_returns_401_session_revoked(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willThrowException( new SessionRevokedException() );

        $controller = $this->newController( $authorizer, time() );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-revoked-jwt', [ 'season_id' => self::SEASON_ID ] ) );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'session_revoked', $response->get_data()['code'] );
    }

    public function test_unexpected_exception_returns_generic_500_and_logs_identifiers(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        $fechaRepository = $this->createMock( FechaRepository::class );
        $fechaRepository->method( 'listBySeason' )->willThrowException( new \RuntimeException( 'simulated read failure' ) );

        $controller = new FechaController( $authorizer, $fechaRepository, $this->settings, $this->eventLog, fn (): int => time() );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [ 'season_id' => self::SEASON_ID ] ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertTrue( $this->eventLog->has( 'rest.fecha_abierta_fallida' ) );
    }

    /**
     * THE test that actually closes the gap the read-failure audit called
     * out: `test_unexpected_exception_returns_generic_500_and_logs_identifiers()`
     * above only proves the CONTROLLER handles a throw — it mocks
     * FechaRepository entirely, so it can never catch a regression where
     * `FechaRepository::listBySeason()` itself goes back to swallowing a
     * wpdb-level failure into `[]`. This test drives the REAL repository
     * against the SQLite test shim, with a `\wpdb` double that fails ONLY
     * `listBySeason()`'s own query, and asserts the endpoint answers a real
     * error — never the calm `{"fecha": null}` a captain would otherwise see
     * with no explanation anywhere.
     */
    public function test_get_fecha_abierta_returns_a_real_error_when_the_underlying_read_fails(): void {
        global $wpdb;

        $this->seedFecha( 3, 1, '2026-01-10', 'programada' );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => 777 ] );

        $failingWpdb            = $this->wpdbThatFailsGetResults( $wpdb, 'ORDER BY orden ASC' );
        $failingFechaRepository = new FechaRepository( $failingWpdb, $this->eventLog );

        $now        = ( new \DateTimeImmutable( '2026-01-05 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $controller = new FechaController( $authorizer, $failingFechaRepository, $this->settings, $this->eventLog, fn (): int => $now );

        $response = $controller->fechaAbierta( $this->requestConToken( 'a-valid-jwt', [ 'season_id' => self::SEASON_ID ] ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertNotSame(
            [ 'fecha' => null ],
            $response->get_data(),
            'A failed read must never look identical to "no fecha open right now".'
        );
        $this->assertTrue( $this->eventLog->has( 'rest.fecha_abierta_fallida' ) );
        $this->assertTrue( $this->eventLog->has( 'lectura.fallida' ), 'FechaRepository::listBySeason() must log its own read failure too.' );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A `\wpdb` subclass whose get_results() sets $wpdb->last_error and
     * returns [] whenever the SQL contains $mustContain — the same double
     * `Plazas\PlazaRepositoryTest::wpdbThatFailsGetResults()` uses, copied
     * here so this suite can drive a REAL `FechaRepository` into a genuine
     * read failure instead of mocking the repository away.
     */
    private function wpdbThatFailsGetResults( \wpdb $real, string $mustContain ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $mustContain ) extends \wpdb {
            private string $mustContain;

            public function __construct( \PDO $pdo, string $prefix, string $mustContain ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix      = $prefix;
                $this->mustContain = $mustContain;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    $this->last_error = 'simulated get_results failure for test';
                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };
    }

    private function newController( CapitanAuthorizer $authorizer, int $now ): FechaController {
        return new FechaController( $authorizer, $this->fechaRepository, $this->settings, $this->eventLog, fn (): int => $now );
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

    private function seedFecha( int $fechaId, int $orden, string $playDate, string $estado ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => self::SEASON_ID,
                'orden'              => $orden,
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
}
