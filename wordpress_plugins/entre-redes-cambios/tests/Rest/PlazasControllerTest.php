<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CandidatoEstado;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
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
            [ 'id' => 1, 'tipo' => 'titular', 'titular_player_id' => 777, 'closed_at' => null ],
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

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $this->createMock( CandidatosResolver::class ) );

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

    // -------------------------------------------------------------------------
    // listarCandidatos()
    // -------------------------------------------------------------------------

    private const PLAZA_ID = 1;

    public function test_listar_candidatos_happy_path_shapes_every_candidato(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->method( 'findPlaza' )->with( self::PLAZA_ID )->willReturn( [
            'id' => self::PLAZA_ID, 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'puntaje_techo' => 6,
        ] );

        $fechaRepository = $this->createMock( FechaRepository::class );

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )
            ->method( 'paraPlaza' )
            ->willReturn( [
                new CandidatoEstado( 800, true, Puntaje::fromDecimal( 2.5 ), true, null ),
                new CandidatoEstado( 801, false, null, false, 'puntaje_indeterminado' ),
            ] );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => self::PLAZA_ID,
        ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [
                [ 'player_id' => 800, 'es_padre' => true, 'puntaje' => 2.5, 'viable' => true, 'motivo' => null ],
                [ 'player_id' => 801, 'es_padre' => false, 'puntaje' => null, 'viable' => false, 'motivo' => 'puntaje_indeterminado' ],
            ],
            $response->get_data()['candidatos']
        );
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

    public function test_listar_candidatos_returns_403_without_touching_the_resolver(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new InvalidTokenException() );

        $plazaRepository = $this->createMock( PlazaRepository::class );
        $plazaRepository->expects( $this->never() )->method( 'findPlaza' );

        $fechaRepository    = $this->createMock( FechaRepository::class );
        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->never() )->method( 'paraPlaza' );

        $controller = new PlazasController( $authorizer, $plazaRepository, $fechaRepository, $this->eventLog, $candidatosResolver );

        $response = $controller->listarCandidatos( $this->requestConToken( 'a-valid-jwt', [
            'season_id' => self::SEASON_ID,
            'team_id'   => self::TEAM_ID,
            'plaza_id'  => self::PLAZA_ID,
        ] ) );

        $this->assertSame( 403, $response->get_status() );
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
        $candidatosResolver->expects( $this->never() )->method( 'paraPlaza' );

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
            $candidatosResolver->method( 'paraPlaza' )->willReturnCallback(
                static function ( array $plaza, $politica, callable $countResolvedFechasSinceFn ): array {
                    // Exactly what the real CandidatosResolver does
                    // internally when it evaluates bloqueo por cierre
                    // truncado — invoking the injected counter is what must
                    // fail closed here.
                    $countResolvedFechasSinceFn( 1 );

                    return [];
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
