<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Plazas\CandidatoEstado;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\CandidatosSeccion;
use EntreRedes\Cambios\Plazas\ListaEsperaResolver;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
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
                [ 'id' => 1, 'titular_player_id' => 777, 'closed_at' => null, 'puntaje_techo' => 6 ],
                [ 'id' => 2, 'titular_player_id' => 888, 'closed_at' => '2026-01-01 00:00:00', 'puntaje_techo' => 10 ],
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
        // Calendario\BoundedFechaCounter caps every count against the season's
        // OWN total of resolved fechas, so a fixture that reports resolved
        // fechas must also own enough of them — otherwise the cap correctly
        // reads the count as inflated and degrades the plaza.
        $fechaRepository->method( 'listBySeason' )->willReturn( self::fechasResueltas( 23 ) );
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

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

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
        $this->assertSame( 3.0, $plazas[0]['puntaje_techo'] );
        // meetsMinimo/countFechasUntilLiberacion: min(3) - resueltas(1) = 2 faltantes.
        $this->assertSame( 2, $plazas[0]['fechas_faltantes_liberacion'] );

        $this->assertSame( 2, $plazas[1]['plaza_id'] );
        $this->assertSame( 999, $plazas[1]['ocupante_player_id'] );
        $this->assertFalse( $plazas[1]['es_titular_el_ocupante'] );
        $this->assertTrue( $plazas[1]['cerrada'] );
        // resueltas(5) >= minimo(3) => ya liberable, 0 faltantes.
        $this->assertSame( 0, $plazas[1]['fechas_faltantes_liberacion'] );
    }

    /**
     * FIX 2: `titular_nombre` / `ocupante_nombre` resolve to the real post
     * title when one is set, and fall back to "Jugador #<id>" — never an
     * empty string — when it is not (plaza 2's titular, 888, has no title
     * seeded here).
     */
    public function test_listar_shapes_titular_and_ocupante_names_with_fallback(): void {
        global $wp_test_post_titles;
        $wp_test_post_titles = [ 777 => 'Juan Pérez', 999 => 'Martín Gómez' ];

        try {
            $authorizer = $this->createMock( CapitanAuthorizer::class );
            $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

            $plazaRepository = $this->createMock( PlazaRepository::class );
            $plazaRepository->method( 'listPlazasByEquipo' )->willReturn( [
                [ 'id' => 1, 'titular_player_id' => 777, 'closed_at' => null, 'puntaje_techo' => 6 ],
                [ 'id' => 2, 'titular_player_id' => 888, 'closed_at' => null, 'puntaje_techo' => 6 ],
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
            $fechaRepository->method( 'listBySeason' )->willReturn( self::fechasResueltas( 23 ) );
            $fechaRepository->method( 'countResolvedFechasSince' )->willReturn( 5 );

            $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

            $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
                'season_id' => self::SEASON_ID,
                'team_id'   => self::TEAM_ID,
            ] ) );

            $plazas = $response->get_data()['plazas'];

            // Plaza 1: titular AND ocupante are both player 777 — real title.
            $this->assertSame( 'Juan Pérez', $plazas[0]['titular_nombre'] );
            $this->assertSame( 'Juan Pérez', $plazas[0]['ocupante_nombre'] );

            // Plaza 2: titular is 888 (no title seeded => fallback), ocupante
            // is 999 (real title).
            $this->assertSame( 'Jugador #888', $plazas[1]['titular_nombre'] );
            $this->assertSame( 'Martín Gómez', $plazas[1]['ocupante_nombre'] );
        } finally {
            $wp_test_post_titles = [];
        }
    }

    /**
     * `PlazaRepository::listOcupaciones()` now THROWS on a wpdb-level read
     * failure (see its own docblock) — a genuinely empty chain reaching
     * `shapePlaza()` is therefore only the DEFENSIVE fallback for a plaza
     * that was somehow persisted with no ocupaciones at all, never a stand-in
     * for "the read failed" (see `resolveFechasFaltantes()`'s own docblock).
     * This test mocks that empty array directly, which still exercises the
     * fallback branch; `test_listar_returns_a_real_error_when_the_underlying_read_fails()`
     * below is what actually proves a REAL read failure now aborts the whole
     * response instead of reaching this branch at all.
     */
    public function test_listar_defensively_reads_fechas_faltantes_as_null_when_ocupaciones_is_empty(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'listPlazasByEquipo' )->willReturn( [
            [ 'id' => 9, 'titular_player_id' => 777, 'closed_at' => null, 'puntaje_techo' => 6 ],
        ] );
        $plazaRepository->method( 'listOcupaciones' )->willReturn( [] );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

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
            [ 'id' => 1, 'titular_player_id' => 777, 'closed_at' => null, 'puntaje_techo' => 6 ],
            [ 'id' => 2, 'titular_player_id' => 888, 'closed_at' => null, 'puntaje_techo' => 6 ],
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
        // Calendario\BoundedFechaCounter caps every count against the season's
        // OWN total of resolved fechas, so a fixture that reports resolved
        // fechas must also own enough of them — otherwise the cap correctly
        // reads the count as inflated and degrades the plaza.
        $fechaRepository->method( 'listBySeason' )->willReturn( self::fechasResueltas( 23 ) );
        $fechaRepository->method( 'countResolvedFechasSince' )->willReturnCallback(
            function ( int $seasonId, int $fechaId ): int {
                if ( 1 === $fechaId ) {
                    throw new \RuntimeException( 'conteo roto para fecha 1' );
                }

                return 3; // plaza 2: ya liberable.
            }
        );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

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

    /**
     * The cap is not decoration on this endpoint either.
     * `fechas_faltantes_liberacion` is what tells a capitan whether the
     * titular may come back yet, and an INFLATED count makes that number too
     * SMALL — without the cap the screen would read "0 faltantes, pedilo" for
     * a plaza the dictamen engine (whose own path is bounded) then rejects.
     * Plazas\CadenaResolver cannot notice an inflated count on its own, so
     * this is the only place that ever could.
     */
    public function test_listar_degrades_a_plaza_whose_liberation_count_is_inflated(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'listPlazasByEquipo' )->willReturn( [
            [ 'id' => 1, 'titular_player_id' => 777, 'closed_at' => null, 'puntaje_techo' => 6 ],
        ] );
        $plazaRepository->method( 'listOcupaciones' )->willReturn( [
            [ 'id' => 1, 'plaza_id' => 1, 'player_id' => 888, 'es_genesis' => 0, 'fecha_desde_id' => 1, 'fecha_hasta_id' => null, 'cerrada_por' => null ],
        ] );

        $fechaRepository = $this->createMock( FechaRepository::class );
        // The season owns 2 resolved fechas, but the counter claims 9 have
        // passed since fecha 1 — impossible, so the count cannot be trusted.
        $fechaRepository->method( 'listBySeason' )->willReturn( self::fechasResueltas( 2 ) );
        $fechaRepository->method( 'countResolvedFechasSince' )->willReturn( 9 );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        // The response still succeeds — one plaza failing does not fail the
        // whole list — but it says "indeterminado" instead of the optimistic
        // "0 faltantes" an unbounded count would have produced.
        $this->assertSame( 200, $response->get_status() );
        $plaza = $response->get_data()['plazas'][0];

        $this->assertNull( $plaza['fechas_faltantes_liberacion'] );
        $this->assertTrue( $plaza['fechas_faltantes_liberacion_indeterminado'] );

        $this->assertTrue( $this->eventLog->has( 'contador.fechas_resueltas_inflado' ) );
        $this->assertTrue( $this->eventLog->has( 'rest.plaza_fechas_faltantes_no_calculable' ) );
    }

    public function test_listar_missing_season_or_team_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->never() )->method( 'listPlazasByEquipo' );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [ 'season_id' => self::SEASON_ID ] ) );

        $this->assertSame( 400, $response->get_status() );
    }

    /**
     * THE WIRING GUARANTEE, same as SolicitudesControllerTest, UPDATED for
     * FIX 1 of the slice 5 task brief: an invalid token (no wrapped
     * TokenVerificationException, exactly like a missing/malformed
     * Authorization header) now returns 401 `token_invalid`, not the old
     * blanket 403 — and NEVER touches PlazaRepository, asserted with a
     * double that fails the test if called.
     */
    public function test_listar_returns_401_token_invalid_without_touching_the_repository(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new InvalidTokenException() );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->never() )->method( 'listPlazasByEquipo' );
        $plazaRepository->expects( $this->never() )->method( 'listOcupaciones' );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame(
            [
                'code'    => 'token_invalid',
                'message' => 'No estás autorizado para realizar esta acción en este equipo y temporada.',
                'data'    => [ 'status' => 401 ],
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

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

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

    /**
     * THE test that closes the read-failure audit's gap for `/plazas`,
     * mirroring `Rest\FechaControllerTest`'s own real-repository test: every
     * OTHER test in this class mocks `PlazaRepository` entirely, which proves
     * the CONTROLLER handles a throw but never that
     * `PlazaRepository::listPlazasByEquipo()` itself actually throws now.
     * This drives a REAL `PlazaRepository` against the SQLite test shim, with
     * a `\wpdb` double that fails ONLY that method's own query, and asserts
     * the endpoint answers a real error — never a 200 with an empty roster a
     * captain with real plazas would otherwise see with no explanation
     * anywhere.
     */
    public function test_listar_returns_a_real_error_when_the_underlying_read_fails(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );

        try {
            $authorizer = $this->createMock( CapitanAuthorizer::class );
            $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

            $failingWpdb            = $this->wpdbThatFailsGetResults( $wpdb, 'cambios_plaza' );
            $failingPlazaRepository = new PlazaRepository( $failingWpdb, $this->eventLog );

            $fechaRepository = $this->createMock( FechaRepository::class );

            $controller = new PlazasController( $authorizer, $failingPlazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

            $response = $controller->listar( $this->requestConToken( 'a-valid-jwt', [
                'season_id' => self::SEASON_ID,
                'team_id'   => self::TEAM_ID,
            ] ) );

            $this->assertSame( 500, $response->get_status() );
            $this->assertNotSame(
                [ 'plazas' => [] ],
                $response->get_data(),
                'A failed read must never look identical to "this team genuinely has no plazas".'
            );
            $this->assertTrue( $this->eventLog->has( 'rest.plazas_listar_fallida' ) );
            $this->assertTrue(
                $this->eventLog->has( 'lectura.fallida' ),
                'PlazaRepository::listPlazasByEquipo() must log its own read failure too.'
            );
        } finally {
            $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        }
    }

    // -------------------------------------------------------------------------
    // listarCandidatos()
    // -------------------------------------------------------------------------

    private const PLAZA_ID = 1;

    /**
     * @return CandidatosResolver&\PHPUnit\Framework\MockObject\MockObject
     */
    private function candidatosResolverConDosCandidatos(): CandidatosResolver {
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->method( 'buscarPaginado' )
            ->willReturn( [
                'candidatos' => [
                    new CandidatoEstado( 800, true, Puntaje::fromDecimal( 2.5 ), true, null ),
                    new CandidatoEstado( 801, false, null, false, 'puntaje_indeterminado' ),
                ],
                'total' => 2,
            ] );

        return $candidatosResolver;
    }

    /** @param array<string, mixed> $extraParams */
    private function requestParaCandidatos( array $extraParams = [] ): \WP_REST_Request {
        return $this->requestConToken( 'a-valid-jwt', array_merge(
            [
                'season_id' => self::SEASON_ID,
                'team_id'   => self::TEAM_ID,
                'plaza_id'  => self::PLAZA_ID,
            ],
            $extraParams
        ) );
    }

    /**
     * FIX 3: viable-only by default — a captain cannot act on a non-viable
     * candidate, so 801 (puntaje_indeterminado, not viable) is excluded
     * unless `incluir_no_viables=1` is passed (see the next test).
     */
    public function test_listar_candidatos_default_returns_only_viable_candidatos(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->candidatosResolverConDosCandidatos();

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [
                [ 'player_id' => 800, 'nombre' => 'Jugador #800', 'es_padre' => true, 'puntaje' => 2.5, 'viable' => true, 'motivo' => null, 'foto_url' => null ],
            ],
            $response->get_data()['candidatos']
        );
        // See listarCandidatos()'s own docblock, "PAGINATION": the header
        // reports the POPULATION size (2, both candidates), not how many
        // survived the viable-only filter (1) — that's the whole point of
        // resolving it before the viability filter runs.
        $this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
    }

    /**
     * FIX 3: `?incluir_no_viables=1` opts back into the FULL list — the
     * committee's own tooling may want to see WHY a candidate was excluded.
     */
    public function test_listar_candidatos_incluir_no_viables_returns_the_full_list_with_motivo(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->candidatosResolverConDosCandidatos();

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'incluir_no_viables' => '1' ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [
                [ 'player_id' => 800, 'nombre' => 'Jugador #800', 'es_padre' => true, 'puntaje' => 2.5, 'viable' => true, 'motivo' => null, 'foto_url' => null ],
                [ 'player_id' => 801, 'nombre' => 'Jugador #801', 'es_padre' => false, 'puntaje' => null, 'viable' => false, 'motivo' => 'puntaje_indeterminado', 'foto_url' => null ],
            ],
            $response->get_data()['candidatos']
        );
    }

    /**
     * `?search=`/`?puntajes[]=` are no longer applied by the controller
     * itself — see CandidatosResolver::buscarPaginado()'s own docblock for
     * why they now narrow the POPULATION, inside the resolver, BEFORE
     * pagination (CandidatosResolverTest covers the actual matching
     * semantics). This test proves the controller still PARSES both and
     * forwards them correctly — the delegation contract this layer owns.
     */
    public function test_listar_candidatos_forwards_search_and_puntajes_filtro_to_buscar_paginado(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plaza           = [ 'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6 ];
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( $plaza );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )
            ->method( 'buscarPaginado' )
            ->with(
                $plaza,
                null,
                null,
                $this->isInstanceOf( BloqueoReemplazoPolicy::class ),
                $this->isType( 'callable' ),
                1,
                20,
                'gómez',
                [ 2.5, 4.0 ]
            )
            ->willReturn( [ 'candidatos' => [], 'total' => 0 ] );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [
            'search'   => 'gómez',
            'puntajes' => [ '2.5', '4' ],
        ] ) );

        $this->assertSame( 200, $response->get_status() );
    }

    /**
     * `?page=`/`?per_page=` default to 1/DEFAULT_PER_PAGE, are clamped to
     * >= 1, and `?per_page=` is additionally clamped to MAX_PER_PAGE — a
     * caller cannot opt back into "the whole population in one response".
     */
    public function test_listar_candidatos_clamps_page_and_per_page(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plaza           = [ 'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6 ];
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( $plaza );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )
            ->method( 'buscarPaginado' )
            ->with(
                $plaza,
                null,
                null,
                $this->isInstanceOf( BloqueoReemplazoPolicy::class ),
                $this->isType( 'callable' ),
                1,   // page=-5 clamped up to 1
                100, // per_page=99999 clamped down to MAX_PER_PAGE
                '',
                []
            )
            ->willReturn( [ 'candidatos' => [], 'total' => 0 ] );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [
            'page'     => '-5',
            'per_page' => '99999',
        ] ) );

        $this->assertSame( 200, $response->get_status() );
    }

    /**
     * `X-WP-Total` reports buscarPaginado()'s own `total` — the SAME
     * convention `entre-redes-api`'s `/jugadores` endpoint already uses and
     * `ApiService.getJugadoresRaw()` already reads — even when the current
     * page is empty (an out-of-range `?page=`, see
     * CandidatosResolverTest::test_buscar_paginado_out_of_range_page_is_an_empty_page_not_an_error()).
     */
    public function test_listar_candidatos_reports_x_wp_total_header_even_on_an_empty_page(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->method( 'buscarPaginado' )->willReturn( [ 'candidatos' => [], 'total' => 37 ] );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'page' => '99' ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $response->get_data()['candidatos'] );
        $this->assertSame( '37', $response->get_headers()['X-WP-Total'] );
    }

    /**
     * `foto_url` is resolved via the injected `$fotoResolverFn` — never a
     * direct WordPress call the test suite could not otherwise exercise.
     * `false`/`''` both collapse to `null` (the app's fallback-to-icon
     * signal); a real URL passes through unchanged.
     */
    public function test_listar_candidatos_shapes_foto_url_via_the_injected_resolver(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->method( 'buscarPaginado' )->willReturn( [
            'candidatos' => [
                new CandidatoEstado( 800, true, Puntaje::fromDecimal( 2.5 ), true, null ),
                new CandidatoEstado( 801, false, Puntaje::fromDecimal( 2.5 ), true, null ),
            ],
            'total' => 2,
        ] );

        $fotoResolverFn = static fn ( int $playerId ): string|false => 800 === $playerId
            ? 'https://entreredespadres.com.ar/foto-800.jpg'
            : '';

        $controller = new PlazasController(
            $authorizer,
            $plazaRepository,
            $fechaRepository,
            $this->eventLog,
            $candidatosResolver,
            null,
            null,
            null,
            $fotoResolverFn
        );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos() );

        $candidatos = $response->get_data()['candidatos'];
        $this->assertSame( 'https://entreredespadres.com.ar/foto-800.jpg', $candidatos[0]['foto_url'] );
        $this->assertNull( $candidatos[1]['foto_url'], 'An empty string from the resolver must collapse to null, never a broken image URL.' );
    }

    /**
     * A resolver that THROWS for one candidate must degrade only that
     * candidate's `foto_url` to null — never abort the whole response with a
     * 500 — and the failure must still be observable through EventLog,
     * summarized once for the page rather than once per failing row.
     */
    public function test_listar_candidatos_tolerates_a_throwing_foto_resolver(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->method( 'buscarPaginado' )->willReturn( [
            'candidatos' => [
                new CandidatoEstado( 800, true, Puntaje::fromDecimal( 2.5 ), true, null ),
                new CandidatoEstado( 801, false, Puntaje::fromDecimal( 2.5 ), true, null ),
            ],
            'total' => 2,
        ] );

        $fotoResolverFn = static function ( int $playerId ): string|false {
            if ( 800 === $playerId ) {
                throw new \RuntimeException( 'simulated storage failure' );
            }

            return 'https://entreredespadres.com.ar/foto-801.jpg';
        };

        $controller = new PlazasController(
            $authorizer,
            $plazaRepository,
            $fechaRepository,
            $this->eventLog,
            $candidatosResolver,
            null,
            null,
            null,
            $fotoResolverFn
        );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos() );

        $this->assertSame( 200, $response->get_status(), 'One bad photo must never turn the whole page into a 500.' );

        $candidatos = $response->get_data()['candidatos'];
        $this->assertNull( $candidatos[0]['foto_url'], 'The throwing candidate degrades to null, like a missing photo.' );
        $this->assertSame( 'https://entreredespadres.com.ar/foto-801.jpg', $candidatos[1]['foto_url'], 'The rest of the page must remain intact.' );

        $this->assertTrue( $this->eventLog->has( 'rest.foto_jugador_fallida' ), 'The failure must be observable through EventLog.' );
        $evento = $this->eventLog->last();
        $this->assertSame( [ 800 ], $evento['contexto']['player_ids'] );
        $this->assertSame( 1, $evento['contexto']['count'] );
    }

    public function test_listar_candidatos_missing_fields_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $fechaRepository = $this->createMock( FechaRepository::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

        $response = $controller->listarCandidatos( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_listar_candidatos_returns_401_token_invalid_without_touching_the_resolver(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new InvalidTokenException() );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->never() )->method( 'findPlaza' );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->never() )->method( 'buscarPaginado' );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => self::PLAZA_ID,
        ] ) );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'token_invalid', $response->get_data()['code'] );
    }

    public function test_listar_candidatos_returns_400_when_the_plaza_does_not_match_season_or_team(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => 999999, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->never() )->method( 'buscarPaginado' );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => self::PLAZA_ID,
        ] ) );

        $this->assertSame( 400, $response->get_status() );
    }

    /**
     * FIX 5: `listarCandidatos()` must route its resolved-fechas counter
     * through `Calendario\BoundedFechaCounter` — the SAME bounded, capped
     * collaborator `Dictamen\DictamenContextAssembler` uses — so an inflated
     * counter makes THIS endpoint fail closed too, instead of showing an
     * optimistic candidatos list the dictamen engine would then reject. This
     * suite mocks CandidatosResolver wholesale everywhere else, which never
     * exercises the real closure — here a REAL `FechaRepository` (against
     * the SQLite shim) deliberately lies upward, exactly like
     * DictamenContextAssemblerTest's own "inflated counter" fixture, and the
     * mocked CandidatosResolver invokes the injected counter itself (as the
     * real one would internally) to prove the wiring actually caps it.
     */
    public function test_listar_candidatos_fails_closed_when_the_fecha_counter_is_inflated(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        // The season has ZERO resolved fechas (estado 'programada') — the
        // bounded counter's own total is therefore 0, so ANY positive count
        // reported for it is impossible.
        $wpdb->insert( $p . 'cambios_fecha', [
            'id'                 => 1,
            'season_id'          => self::SEASON_ID,
            'orden'              => 1,
            'torneo_liga_ids'    => '1',
            'torneo_label'       => 'Apertura',
            'numero_en_torneo'   => 1,
            'play_date'          => '2026-05-30',
            'play_date_original' => '2026-05-30',
            'estado'             => 'programada',
            'created_at'         => '2026-01-01 00:00:00',
            'updated_at'         => '2026-01-01 00:00:00',
        ] );

        try {
            $authorizer = $this->createMock( CapitanAuthorizer::class );
            $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

            $plazaRepository = $this->createMock( PlazaRepository::class );
            $plazaRepository->method( 'findPlaza' )->willReturn( [
                'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
            ] );

            // A REAL FechaRepository against the SQLite shim, deliberately
            // lying upward — same fixture shape as
            // DictamenContextAssemblerTest's inflating FechaRepository.
            $inflatingFechaRepository = new class( $wpdb, new InMemoryEventLog() ) extends FechaRepository {
                public function countResolvedFechasSince( int $seasonId, int $fechaId ): int {
                    return 999;
                }
            };

            $candidatosResolver = $this->createMock( CandidatosResolver::class );
            $candidatosResolver->method( 'buscarPaginado' )->willReturnCallback(
                static function ( array $plaza, ?string $seccion, ?int $listaEsperaTeamId, $politica, callable $countResolvedFechasSinceFn ): array {
                    // Exactly what the real CandidatosResolver does
                    // internally when it evaluates bloqueo por cierre
                    // truncado — invoking the injected counter is what must
                    // fail closed here.
                    $countResolvedFechasSinceFn( 1 );

                    return [ 'candidatos' => [], 'total' => 0 ];
                }
            );

            $controller = new PlazasController( $authorizer, $plazaRepository, $inflatingFechaRepository, $this->eventLog, $candidatosResolver );

            $response = $controller->listarCandidatos( $this->requestConToken( 'a-valid-jwt', [
                'season_id' => self::SEASON_ID,
                'team_id'   => self::TEAM_ID,
                'plaza_id'  => self::PLAZA_ID,
            ] ) );

            $this->assertSame( 500, $response->get_status() );
            $this->assertSame( 'error_interno', $response->get_data()['code'] );
            $this->assertTrue( $this->eventLog->has( 'rest.plazas_candidatos_fallida' ) );
        } finally {
            $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        }
    }

    // -------------------------------------------------------------------------
    // listarCandidatos() — ?seccion=
    // -------------------------------------------------------------------------

    /**
     * Omitting `?seccion` keeps the EXACT pre-existing population — a `null`
     * `$seccion` passed to `buscarPaginado()` — for any caller written before
     * these two sections existed, and never resolves a lista-de-espera team
     * id it does not need (`$listaEsperaTeamId` stays `null` too).
     */
    public function test_listar_candidatos_without_seccion_passes_null_seccion_to_buscar_paginado(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plaza           = [ 'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6 ];
        $plazaRepository->method( 'findPlaza' )->willReturn( $plaza );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )
            ->method( 'buscarPaginado' )
            ->with( $plaza, null, null, $this->isInstanceOf( BloqueoReemplazoPolicy::class ), $this->isType( 'callable' ), 1, 20, '', [] )
            ->willReturn( [ 'candidatos' => [], 'total' => 0 ] );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos() );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_listar_candidatos_seccion_invalida_returns_400(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->never() )->method( 'authorize' );

        $plazaRepository    = $this->createMock( PlazaRepository::class );
        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'seccion' => 'no_existe' ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'seccion_invalida', $response->get_data()['code'] );
    }

    /** @return ListaEsperaResolver&\PHPUnit\Framework\MockObject\MockObject */
    private function listaEsperaResolverQueResuelve( int $teamId ): ListaEsperaResolver {
        $listaEsperaResolver = $this->createMock( ListaEsperaResolver::class );
        $listaEsperaResolver->method( 'resolve' )->with( self::SEASON_ID )->willReturn( $teamId );

        return $listaEsperaResolver;
    }

    public function test_listar_candidatos_seccion_lista_espera_delegates_to_buscar_paginado_with_the_resolved_team_id(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plaza           = [ 'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6 ];
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( $plaza );

        $fechaRepository     = $this->createMock( FechaRepository::class );
        $listaEsperaResolver = $this->listaEsperaResolverQueResuelve( 14349 );

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )
            ->method( 'buscarPaginado' )
            ->with(
                $plaza,
                CandidatosSeccion::LISTA_ESPERA,
                14349,
                $this->isInstanceOf( BloqueoReemplazoPolicy::class ),
                $this->isType( 'callable' ),
                1,
                20,
                '',
                []
            )
            ->willReturn( [
                'candidatos' => [ new CandidatoEstado( 800, true, Puntaje::fromDecimal( 2.5 ), true, null ) ],
                'total'      => 1,
            ] );

        $controller = new PlazasController(
            $authorizer,
            $plazaRepository,
            $fechaRepository,
            $this->eventLog,
            $candidatosResolver,
            null,
            null,
            $listaEsperaResolver
        );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'seccion' => CandidatosSeccion::LISTA_ESPERA ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [ [ 'player_id' => 800, 'nombre' => 'Jugador #800', 'es_padre' => true, 'puntaje' => 2.5, 'viable' => true, 'motivo' => null, 'foto_url' => null ] ],
            $response->get_data()['candidatos']
        );
    }

    public function test_listar_candidatos_seccion_padron_completo_delegates_to_buscar_paginado(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plaza           = [ 'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6 ];
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( $plaza );

        $fechaRepository     = $this->createMock( FechaRepository::class );
        $listaEsperaResolver = $this->listaEsperaResolverQueResuelve( 14349 );

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )
            ->method( 'buscarPaginado' )
            ->with(
                $plaza,
                CandidatosSeccion::PADRON_COMPLETO,
                14349,
                $this->isInstanceOf( BloqueoReemplazoPolicy::class ),
                $this->isType( 'callable' ),
                1,
                20,
                '',
                []
            )
            ->willReturn( [ 'candidatos' => [], 'total' => 0 ] );

        $controller = new PlazasController(
            $authorizer,
            $plazaRepository,
            $fechaRepository,
            $this->eventLog,
            $candidatosResolver,
            null,
            null,
            $listaEsperaResolver
        );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'seccion' => CandidatosSeccion::PADRON_COMPLETO ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $response->get_data()['candidatos'] );
    }

    /**
     * THE loud-failure contract: when ListaEsperaResolver cannot resolve the
     * team id, the WHOLE response fails (500, logged) — never a silent
     * empty `candidatos: []`, which would read to a captain as "nobody
     * signed up". See ListaEsperaResolver's own class docblock.
     */
    public function test_listar_candidatos_seccion_fails_loud_when_the_team_id_cannot_be_resolved(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $listaEsperaResolver = $this->createMock( ListaEsperaResolver::class );
        $listaEsperaResolver->method( 'resolve' )->willThrowException(
            new \EntreRedes\Cambios\Plazas\Exception\ListaEsperaTeamUnresolvableException( 'test' )
        );

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->never() )->method( 'buscarPaginado' );

        $controller = new PlazasController(
            $authorizer,
            $plazaRepository,
            $fechaRepository,
            $this->eventLog,
            $candidatosResolver,
            null,
            null,
            $listaEsperaResolver
        );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'seccion' => CandidatosSeccion::LISTA_ESPERA ] ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertTrue( $this->eventLog->has( 'rest.plazas_candidatos_fallida' ) );
    }

    /**
     * A controller constructed WITHOUT a ListaEsperaResolver (the
     * nullable-for-backward-compatibility default) must still fail loud, not
     * silently, when a seccion IS requested against it — see the
     * constructor's own docblock.
     */
    public function test_listar_candidatos_seccion_without_a_wired_resolver_fails_loud(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestParaCandidatos( [ 'seccion' => CandidatosSeccion::LISTA_ESPERA ] ) );

        $this->assertSame( 500, $response->get_status() );
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

    /**
     * A `\wpdb` subclass whose get_results() sets $wpdb->last_error and
     * returns [] whenever the SQL contains $mustContain — same double as
     * `Plazas\PlazaRepositoryTest::wpdbThatFailsGetResults()`, copied here so
     * this suite can drive a REAL `PlazaRepository` into a genuine read
     * failure instead of mocking the repository away.
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

    /**
     * @return array<int, array<string, mixed>> $n fechas already resolved,
     *         the shape Calendario\FechaRepository::listBySeason() returns and
     *         Calendario\BoundedFechaCounter reads to build its cap.
     */
    private static function fechasResueltas( int $n ): array {
        return array_map(
            static fn ( int $i ): array => [ 'id' => $i, 'orden' => $i, 'estado' => 'jugada' ],
            range( 1, $n )
        );
    }
}
