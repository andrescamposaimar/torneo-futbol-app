<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Rest\SolicitudesController;
use EntreRedes\Cambios\Solicitudes\Lock\CreationLock;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use EntreRedes\Cambios\Tests\Support\InMemoryCreationLock;
use EntreRedes\Cambios\Tests\Support\SeedsSolicitudes;
use PHPUnit\Framework\TestCase;

/**
 * The per-season creation lock (0.1.21) — the PROPERTY, never the mechanism.
 *
 * What is asserted: (1) the open-request reads and the insert — the whole
 * check-then-insert — happen WHILE the lock is held; (2) the lock is released
 * on success, on a duplicate refusal and on an unexpected exception; (3) when
 * it cannot be acquired the answer is a retryable 503 and NOTHING is read or
 * written. A wpdb spy records `InMemoryCreationLock::isHeld()` at every
 * `cambios_solicitud` read and insert, so the assertion sits on the real
 * statements, not on a mock's call order.
 *
 * Concurrency itself cannot be simulated in this single-process SQLite suite;
 * `GET_LOCK`'s own semantics are not tested here (the shim has none — see
 * Solicitudes\Lock\CreationLock).
 */
class SolicitudesControllerCandadoTest extends TestCase {
    use SeedsSolicitudes;

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;

    private InMemoryEventLog $eventLog;
    private InMemoryCreationLock $lock;
    private PlazaRepository $plazaRepository;
    private SolicitudRepository $repo;
    private DictamenPipeline $pipeline;
    private JugadorMetricasReader $metricas;
    private \wpdb $spy;
    private int $fixedNow;
    private int $plazaArco;
    private int $plazaCampo;
    private int $plazaOrdinaria;

    /** @var array<int, array{kind: string, held: bool}> */
    private array $trazas = [];

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT, meta_value TEXT )" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        $this->eventLog = new InMemoryEventLog();
        $this->lock     = new InMemoryCreationLock();
        $this->trazas   = [];

        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->fixedNow        = ( new \DateTimeImmutable( '2026-05-20 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $this->seedFecha( 1, '2026-01-01' );
        $this->seedFecha( 5, gmdate( 'Y-m-d', $this->fixedNow + 3 * DAY_IN_SECONDS ) );

        $wp_test_position_terms = [ 111 => [ 3 ] ];
        $this->plazaArco        = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );
        $wp_test_position_terms = [];
        $this->plazaCampo       = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );
        $this->plazaOrdinaria   = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );

        foreach ( [ 222 => 3.0, 333 => 2.5, 888 => 2.5 ] as $playerId => $puntaje ) {
            $wpdb->insert( $p . 'postmeta', [ 'post_id' => $playerId, 'meta_key' => 'sp_metrics', 'meta_value' => serialize( [ 'puntaje' => (string) $puntaje ] ) ] );
        }

        $this->spy      = $this->spyWpdb( $wpdb );
        $this->metricas = new JugadorMetricasReader( $this->spy, $this->eventLog );

        $assembler      = new DictamenContextAssembler( $this->plazaRepository, new FechaRepository( $this->spy, new InMemoryEventLog() ), new Settings( $this->spy, $this->eventLog ), $this->spy, $this->eventLog );
        $this->pipeline = new DictamenPipeline( $assembler, $this->eventLog );
        $this->repo     = new SolicitudRepository( $this->spy, $this->plazaRepository, $this->pipeline, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wp_test_position_terms = [];
    }

    private function spyWpdb( \wpdb $real ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );
        $test = $this;

        return new class( $pdo, $real->prefix, $test ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix, private SolicitudesControllerCandadoTest $test ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, 'cambios_solicitud' ) ) {
                    $this->test->trace( 'read' );
                }

                return parent::get_results( $sql, $output );
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                if ( str_ends_with( $table, 'cambios_solicitud' ) ) {
                    $this->test->trace( 'insert' );
                }

                return parent::insert( $table, $data, $format );
            }
        };
    }

    /** @internal Called by the wpdb spy. */
    public function trace( string $kind ): void {
        $this->trazas[] = [ 'kind' => $kind, 'held' => $this->lock->isHeld() ];
    }

    private function seedFecha( int $fechaId, string $playDate ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'cambios_fecha', [
            'id' => $fechaId, 'season_id' => self::SEASON_ID, 'orden' => $fechaId, 'torneo_liga_ids' => '1', 'torneo_label' => 'A',
            'numero_en_torneo' => $fechaId, 'play_date' => $playDate, 'play_date_original' => $playDate,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ] );
    }

    private function controller( ?CreationLock $lock, ?SolicitudRepository $repo = null ): SolicitudesController {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        return new SolicitudesController( $authorizer, $repo ?? $this->repo, $this->pipeline, $this->eventLog, $this->plazaRepository, $this->metricas, fn (): int => $this->fixedNow, $lock );
    }

    /** @param array<string, mixed> $params */
    private function crear( SolicitudesController $controller, array $params ): \WP_REST_Response {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer a-valid-jwt' );

        foreach ( array_merge( [ 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'fecha_id' => 5 ], $params ) as $k => $v ) {
            $request->set_param( $k, $v );
        }

        return $controller->crear( $request );
    }

    private function filas(): int {
        global $wpdb;

        return (int) $wpdb->get_results( "SELECT COUNT(*) AS n FROM {$wpdb->prefix}cambios_solicitud", ARRAY_A )[0]['n'];
    }

    /** @return array<string, mixed> */
    private function sustitucion(): array {
        return [ 'plaza_id' => $this->plazaOrdinaria, 'tipo' => 'sustitucion', 'entrante_player_id' => 888 ];
    }

    /** @return array<string, mixed> */
    private function grupo(): array {
        return [ 'plaza_id' => $this->plazaArco, 'tipo' => 'reasignacion_arquero', 'entrante_player_id' => 222, 'entrante_campo_player_id' => 333 ];
    }

    // --- the critical section is entered holding the lock ---------------------

    /** @dataProvider peticiones */
    public function test_the_open_request_reads_and_the_insert_all_happen_while_the_lock_is_held( string $tipo ): void {
        $response = $this->crear( $this->controller( $this->lock ), 'grupo' === $tipo ? $this->grupo() : $this->sustitucion() );

        $this->assertSame( 200, $response->get_status() );

        $lecturas = array_filter( $this->trazas, static fn ( array $t ): bool => 'read' === $t['kind'] );
        $inserts  = array_filter( $this->trazas, static fn ( array $t ): bool => 'insert' === $t['kind'] );
        $this->assertNotEmpty( $lecturas, 'The dictamen must have read the open requests.' );
        $this->assertCount( 1, $inserts );

        foreach ( $this->trazas as $traza ) {
            $this->assertTrue( $traza['held'], "A {$traza['kind']} on cambios_solicitud ran OUTSIDE the lock." );
        }

        $this->assertSame( [ 'acquire', 'release' ], $this->lock->eventos );
        $this->assertSame( [ self::SEASON_ID ], $this->lock->seasons, 'One lock per season.' );
        $this->assertFalse( $this->lock->isHeld() );
    }

    /** @return array<string, array{string}> */
    public static function peticiones(): array {
        return [ 'sustitucion' => [ 'sustitucion' ], 'reasignacion_arquero' => [ 'grupo' ] ];
    }

    // --- released on every exit path ------------------------------------------

    public function test_the_lock_is_released_when_the_request_is_refused_as_a_duplicate(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'entrante_player_id' => 999 ] );
        $this->trazas = [];

        $response = $this->crear( $this->controller( $this->lock ), $this->sustitucion() );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( [ 'acquire', 'release' ], $this->lock->eventos );
        $this->assertFalse( $this->lock->isHeld() );
    }

    public function test_the_lock_is_released_when_the_section_throws_unexpectedly(): void {
        $repo = $this->createMock( SolicitudRepository::class );
        $repo->method( 'crear' )->willThrowException( new \RuntimeException( 'write failed' ) );

        $response = $this->crear( $this->controller( $this->lock, $repo ), $this->sustitucion() );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( [ 'acquire', 'release' ], $this->lock->eventos );
        $this->assertFalse( $this->lock->isHeld(), 'A leaked lock would freeze creation for the whole season.' );
    }

    // --- not acquirable: retryable, and nothing happens -----------------------

    public function test_when_the_lock_cannot_be_acquired_the_answer_is_a_retryable_503_and_nothing_is_read_or_written(): void {
        $busy = new InMemoryCreationLock( false );

        $response = $this->crear( $this->controller( $busy ), $this->sustitucion() );

        $this->assertSame( 503, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'creacion_ocupada', $data['code'] );
        $this->assertNotContains( $data['code'], [ 'plaza_con_solicitud_abierta', 'entrante_con_solicitud_abierta' ], 'It must not be one of the (terminal) 409 codes.' );
        $this->assertStringContainsString( 'Probá de nuevo', $data['message'] );
        $this->assertSame( 503, $data['data']['status'] );

        $this->assertSame( [], $this->trazas, 'Nothing may be evaluated or stored without the lock.' );
        $this->assertSame( 0, $this->filas() );
        $this->assertTrue( $this->eventLog->has( 'rest.solicitud_crear_candado_fallido' ) );
    }

    // --- scope ----------------------------------------------------------------

    public function test_an_invalid_request_never_takes_the_lock(): void {
        $response = $this->crear( $this->controller( $this->lock ), [ 'plaza_id' => 999999, 'tipo' => 'sustitucion', 'entrante_player_id' => 888 ] );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( [], $this->lock->eventos );
    }

    public function test_a_controller_wired_without_a_lock_refuses_to_create_anything(): void {
        $response = $this->crear( $this->controller( null ), $this->sustitucion() );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 0, $this->filas() );
        $this->assertSame( [], $this->trazas );
    }
}
