<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Rest\PlazasController;
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;
use EntreRedes\Cambios\Tests\Support\SeedsSolicitudes;
use PHPUnit\Framework\TestCase;

/**
 * `GET /cambios/plazas` — the `solicitud_abierta` field (0.1.21).
 *
 * Unlike PlazasControllerTest, the SolicitudesAbiertasReader here is a REAL
 * one over the SQLite shim: the contract under test is "resolved for the
 * whole team in ONE query, and a failed read is a 500", which a mock reader
 * could neither count nor fail honestly.
 */
class PlazasControllerSolicitudAbiertaTest extends TestCase {
    use SeedsSolicitudes;

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;

    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_solicitud" );

        $this->eventLog = new InMemoryEventLog();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_solicitud" );
    }

    /** @param array<int, int> $plazaIds */
    private function controller( array $plazaIds, ?SolicitudesAbiertasReader $reader ): PlazasController {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazas = array_map(
            static fn ( int $id ): array => [ 'id' => $id, 'titular_player_id' => 700 + $id, 'closed_at' => null, 'puntaje_techo' => 6 ],
            $plazaIds
        );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'listPlazasByEquipo' )->willReturn( $plazas );
        $plazaRepository->method( 'listOcupaciones' )->willReturnCallback(
            static fn ( int $plazaId ): array => [
                [ 'id' => $plazaId, 'plaza_id' => $plazaId, 'player_id' => 700 + $plazaId, 'es_genesis' => 1, 'fecha_desde_id' => 1, 'fecha_hasta_id' => null, 'cerrada_por' => null ],
            ]
        );

        $fechaRepository = $this->createMock( FechaRepository::class );
        $fechaRepository->method( 'listBySeason' )->willReturn(
            array_map( static fn ( int $i ): array => [ 'id' => $i, 'orden' => $i, 'estado' => 'jugada' ], range( 1, 23 ) )
        );
        $fechaRepository->method( 'countResolvedFechasSince' )->willReturn( 1 );

        return new PlazasController(
            $authorizer,
            $plazaRepository,
            $fechaRepository,
            $this->eventLog,
            $this->createMock( CandidatosResolver::class ),
            solicitudesAbiertas: $reader
        );
    }

    private function listar( PlazasController $controller ): \WP_REST_Response {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer a-valid-jwt' );
        $request->set_param( 'season_id', self::SEASON_ID );
        $request->set_param( 'team_id', self::TEAM_ID );

        return $controller->listar( $request );
    }

    private function realReader(): SolicitudesAbiertasReader {
        global $wpdb;

        return new SolicitudesAbiertasReader( $wpdb, $this->eventLog );
    }

    public function test_solicitud_abierta_is_null_for_a_plaza_without_one(): void {
        $response = $this->listar( $this->controller( [ 1, 2 ], $this->realReader() ) );

        $this->assertSame( 200, $response->get_status() );

        foreach ( $response->get_data()['plazas'] as $plaza ) {
            $this->assertArrayHasKey( 'solicitud_abierta', $plaza, 'The key must be present, not omitted.' );
            $this->assertNull( $plaza['solicitud_abierta'] );
        }
    }

    public function test_solicitud_abierta_carries_id_and_estado_for_a_plaza_with_an_open_request(): void {
        $pendiente = $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => 'pendiente' ] );
        $aprobada  = $this->seedSolicitud( [ 'plaza_id' => 2, 'estado' => 'aprobada', 'entrante_player_id' => 889 ] );

        $plazas = $this->listar( $this->controller( [ 1, 2, 3 ], $this->realReader() ) )->get_data()['plazas'];

        $this->assertSame( [ 'id' => $pendiente, 'estado' => 'pendiente' ], $plazas[0]['solicitud_abierta'] );
        $this->assertSame( [ 'id' => $aprobada, 'estado' => 'aprobada' ], $plazas[1]['solicitud_abierta'] );
        $this->assertNull( $plazas[2]['solicitud_abierta'] );
    }

    /** @dataProvider estadosCerrados */
    public function test_a_closed_request_does_not_mark_the_plaza( string $estado ): void {
        $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => $estado ] );

        $plazas = $this->listar( $this->controller( [ 1 ], $this->realReader() ) )->get_data()['plazas'];

        $this->assertNull( $plazas[0]['solicitud_abierta'] );
    }

    /** @return array<string, array{string}> */
    public static function estadosCerrados(): array {
        return [ 'publicada' => [ 'publicada' ], 'rechazada' => [ 'rechazada' ], 'anulada' => [ 'anulada' ] ];
    }

    public function test_a_grouped_request_marks_both_of_its_plazas(): void {
        $id = $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'plaza_id'                 => 1,
            'plaza_campo_id'           => 2,
            'entrante_player_id'       => 501,
            'entrante_campo_player_id' => 502,
        ] );

        $plazas = $this->listar( $this->controller( [ 1, 2, 3 ], $this->realReader() ) )->get_data()['plazas'];

        $this->assertSame( [ 'id' => $id, 'estado' => 'pendiente' ], $plazas[0]['solicitud_abierta'], 'the goal plaza' );
        $this->assertSame( [ 'id' => $id, 'estado' => 'pendiente' ], $plazas[1]['solicitud_abierta'], 'the vacated field plaza' );
        $this->assertNull( $plazas[2]['solicitud_abierta'] );
    }

    /**
     * "BATCHED, NEVER ONE QUERY PER ROW": eleven plazas, one query against
     * cambios_solicitud.
     */
    public function test_the_whole_team_is_resolved_with_a_single_query(): void {
        global $wpdb;

        foreach ( range( 1, 11 ) as $plazaId ) {
            $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'entrante_player_id' => 800 + $plazaId ] );
        }

        $wpdb->queries = [];

        $this->listar( $this->controller( range( 1, 11 ), $this->realReader() ) );

        $consultas = array_filter( $wpdb->queries, static fn ( string $sql ): bool => str_contains( $sql, 'cambios_solicitud' ) );

        $this->assertCount( 1, $consultas );
    }

    public function test_a_failed_read_is_a_logged_500_never_null_for_every_plaza(): void {
        global $wpdb;

        $this->seedSolicitud( [ 'plaza_id' => 1 ] );

        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $wpdb );

        $failingWpdb = new class( $pdo, $wpdb->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                $this->last_error = 'simulated get_results failure for test';

                return [];
            }
        };

        $response = $this->listar( $this->controller( [ 1 ], new SolicitudesAbiertasReader( $failingWpdb, $this->eventLog ) ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertArrayNotHasKey( 'plazas', $response->get_data() );
        $this->assertTrue( $this->eventLog->has( 'rest.plazas_listar_fallida' ) );
    }

    public function test_a_controller_wired_without_a_reader_fails_loudly_instead_of_answering_null(): void {
        $response = $this->listar( $this->controller( [ 1 ], null ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertTrue( $this->eventLog->has( 'rest.plazas_listar_fallida' ) );
    }
}
