<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Rest\PlazasController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Rest\PlazasController — the plantel-status screen a captain
 * uses to see what a solicitud would be up against before making one. See
 * SolicitudesControllerTest's own docblock for why CapitanAuthorizer,
 * PlazaRepository and FechaRepository are mocked here rather than backed by
 * a real SQLite fixture: this suite tests the CONTROLLER's own contract
 * (authorization-first, shaping, error envelopes), not
 * Plazas\CadenaResolver's liberation math, which CadenaResolverTest already
 * covers.
 */
class PlazasControllerTest extends TestCase {

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;

    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        $this->eventLog = new InMemoryEventLog();
    }

    public function test_listar_happy_path_shapes_every_plaza(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->once() )
            ->method( 'authorize' )
            ->with( 'a-valid-jwt', self::SEASON_ID, self::TEAM_ID, $this->isType( 'int' ) )
            ->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->once() )
            ->method( 'listPlazasByEquipo' )
            ->with( self::SEASON_ID, self::TEAM_ID )
            ->willReturn( [
                [ 'id' => 1, 'tipo' => 'campo', 'titular_player_id' => 777, 'closed_at' => null ],
                [ 'id' => 2, 'tipo' => 'suplente', 'titular_player_id' => 888, 'closed_at' => '2026-01-01 00:00:00' ],
            ] );

        $plazaRepository->method( 'listOcupaciones' )->willReturnMap( [
            [ 1, [
                [ 'id' => 1, 'plaza_id' => 1, 'player_id' => 777, 'es_genesis' => 1, 'fecha_desde_id' => 1, 'fecha_hasta_id' => null, 'cerrada_por' => null ],
            ] ],
            [ 2, [
                [ 'id' => 2, 'plaza_id' => 2, 'player_id' => 888, 'es_genesis' => 1, 'fecha_desde_id' => 1, 'fecha_hasta_id' => 4, 'cerrada_por' => 'reemplazada' ],
                [ 'id' => 3, 'plaza_id' => 2, 'player_id' => 999, 'es_genesis' => 0, 'fecha_desde_id' => 5, 'fecha_hasta_id' => null, 'cerrada_por' => null ],
            ] ],
        ] );

        $fechaRepository = $this->createMock( FechaRepository::class );
        $fechaRepository->method( 'countResolvedFechasSince' )->willReturnCallback(
            function ( int $seasonId, int $fechaId ): int {
                $this->assertSame( self::SEASON_ID, $seasonId );

                return match ( $fechaId ) {
                    1       => 1,
                    5       => 5,
                    default => 0,
                };
            }
        );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $plazas = $response->get_data()['plazas'];
        $this->assertCount( 2, $plazas );

        $this->assertSame( 1, $plazas[0]['plaza_id'] );
        $this->assertSame( 777, $plazas[0]['ocupante_player_id'] );
        $this->assertTrue( $plazas[0]['es_titular_el_ocupante'] );
        $this->assertFalse( $plazas[0]['cerrada'] );
        // meetsMinimo/countFechasUntilLiberacion: min(3) - resueltas(1) = 2 faltantes.
        $this->assertSame( 2, $plazas[0]['fechas_faltantes_liberacion'] );

        $this->assertSame( 2, $plazas[1]['plaza_id'] );
        $this->assertSame( 999, $plazas[1]['ocupante_player_id'] );
        $this->assertFalse( $plazas[1]['es_titular_el_ocupante'] );
        $this->assertTrue( $plazas[1]['cerrada'] );
        // resueltas(5) >= minimo(3) => ya liberable, 0 faltantes.
        $this->assertSame( 0, $plazas[1]['fechas_faltantes_liberacion'] );
    }

    public function test_listar_reads_fechas_faltantes_as_null_when_ocupaciones_could_not_be_read(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'listPlazasByEquipo' )->willReturn( [
            [ 'id' => 9, 'tipo' => 'campo', 'titular_player_id' => 777, 'closed_at' => null ],
        ] );
        // PlazaRepository::listOcupaciones() itself reads a wpdb-level query
        // failure as "no rows" rather than throwing (see its own docblock) —
        // this endpoint must answer "unknown", never a fabricated 0.
        $plazaRepository->method( 'listOcupaciones' )->willReturn( [] );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $plaza = $response->get_data()['plazas'][0];
        $this->assertNull( $plaza['ocupante_player_id'] );
        $this->assertNull( $plaza['fechas_faltantes_liberacion'] );
        // The chain itself could not be READ — a distinct case from the
        // count being unavailable — so this is NOT the "indeterminado" flag.
        $this->assertFalse( $plaza['fechas_faltantes_liberacion_indeterminado'] );
    }

    /**
     * THE degradation this slice adds: `CadenaResolver::countFechasUntilLiberacion()`
     * throwing `FechaCountUnavailableException` for ONE plaza must not fail
     * the whole response (see PlazasController's class docblock, "THIS IS A
     * READ") — the other plaza in the SAME response stays fully computed,
     * proving the degradation is per-row, not all-or-nothing.
     */
    public function test_listar_degrades_a_single_plaza_when_its_liberation_count_is_unavailable(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'listPlazasByEquipo' )->willReturn( [
            [ 'id' => 1, 'tipo' => 'campo', 'titular_player_id' => 777, 'closed_at' => null ],
            [ 'id' => 2, 'tipo' => 'campo', 'titular_player_id' => 888, 'closed_at' => null ],
        ] );
        $plazaRepository->method( 'listOcupaciones' )->willReturnMap( [
            [ 1, [
                [ 'id' => 1, 'plaza_id' => 1, 'player_id' => 777, 'es_genesis' => 1, 'fecha_desde_id' => 1, 'fecha_hasta_id' => null, 'cerrada_por' => null ],
            ] ],
            [ 2, [
                [ 'id' => 2, 'plaza_id' => 2, 'player_id' => 888, 'es_genesis' => 1, 'fecha_desde_id' => 2, 'fecha_hasta_id' => null, 'cerrada_por' => null ],
            ] ],
        ] );

        $fechaRepository = $this->createMock( FechaRepository::class );
        $fechaRepository->method( 'countResolvedFechasSince' )->willReturnCallback(
            function ( int $seasonId, int $fechaId ): int {
                if ( 1 === $fechaId ) {
                    throw new \RuntimeException( 'conteo roto para fecha 1' );
                }

                return 3; // plaza 2: ya liberable.
            }
        );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $plazas = $response->get_data()['plazas'];
        $this->assertCount( 2, $plazas );

        $this->assertNull( $plazas[0]['fechas_faltantes_liberacion'] );
        $this->assertTrue( $plazas[0]['fechas_faltantes_liberacion_indeterminado'] );

        $this->assertSame( 0, $plazas[1]['fechas_faltantes_liberacion'] );
        $this->assertFalse( $plazas[1]['fechas_faltantes_liberacion_indeterminado'] );

        $this->assertTrue( $this->eventLog->has( 'rest.plaza_fechas_faltantes_no_calculable' ) );
        $logged = $this->eventLog->last()['contexto'];
        $this->assertSame( 1, $logged['plaza_id'] );
    }

    public function test_listar_missing_season_or_team_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->never() )->method( 'listPlazasByEquipo' );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [ 'season_id' => self::SEASON_ID ] ) );

        $this->assertSame( 400, $response->get_status() );
    }

    /**
     * THE WIRING GUARANTEE, same as SolicitudesControllerTest: an
     * authorization failure returns 403 and NEVER touches PlazaRepository —
     * asserted with a double that fails the test if called.
     */
    public function test_listar_returns_403_without_touching_the_repository(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new InvalidTokenException() );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->never() )->method( 'listPlazasByEquipo' );
        $plazaRepository->expects( $this->never() )->method( 'listOcupaciones' );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame(
            [
                'code'    => 'no_autorizado',
                'message' => 'No estás autorizado para realizar esta acción en este equipo y temporada.',
                'data'    => [ 'status' => 403 ],
            ],
            $response->get_data()
        );
        $this->assertTrue( $this->eventLog->has( 'rest.autorizacion_denegada' ) );
        $this->assertSame( InvalidTokenException::class, $this->eventLog->last()['contexto']['excepcion'] );
    }

    public function test_listar_unexpected_exception_returns_generic_500_and_logs_identifiers(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'listPlazasByEquipo' )->willThrowException( new \RuntimeException( 'detalle interno sensible' ) );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 500, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'error_interno', $data['code'] );
        $this->assertStringNotContainsString( 'detalle interno sensible', json_encode( $data ) );

        $this->assertTrue( $this->eventLog->has( 'rest.plazas_listar_fallida' ) );
        $logged = $this->eventLog->last()['contexto'];
        $this->assertSame( self::SEASON_ID, $logged['season_id'] );
        $this->assertSame( self::TEAM_ID, $logged['team_id'] );
        $this->assertSame( \RuntimeException::class, $logged['excepcion'] );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

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
