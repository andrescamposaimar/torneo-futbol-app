<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Capitania\Exception\SessionRevokedException;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Rest\CapitanController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Rest\CapitanController — the FIX 1 bootstrap endpoint
 * (`GET /entre-redes/v1/cambios/mis-equipos`) every other captain-facing
 * route in this plugin assumes the client already has a season_id/team_id
 * for. Collaborators are mocked, same convention as PlazasControllerTest /
 * SolicitudesControllerTest: this suite proves THIS controller's own
 * contract (identity-first, never team-scoped; an empty roster is a 200,
 * never a 403; a failed read is a 500, never a silent "captains nothing").
 */
class CapitanControllerTest extends TestCase {

    private const SEASON_ID = 359;
    private const PLAYER_ID = 777;

    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        $this->eventLog = new InMemoryEventLog();
    }

    private function settingsConTemporada(): Settings {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'seasonId' )->willReturn( self::SEASON_ID );

        return $settings;
    }

    /** @param array<string, mixed> $params */
    private function requestConToken( string $token, array $params = [] ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer ' . $token );

        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }

        return $request;
    }

    // -------------------------------------------------------------------------
    // Identity failures — verifyIdentity(), never authorize()
    // -------------------------------------------------------------------------

    public function test_listar_returns_403_for_an_invalid_token_without_touching_the_repository(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->expects( $this->once() )
            ->method( 'verifyIdentity' )
            ->willThrowException( new InvalidTokenException() );

        $capitanRepository = $this->createMock( CapitanRepository::class );
        $capitanRepository->expects( $this->never() )->method( 'listEquiposByCapitan' );

        $controller = new CapitanController( $authorizer, $capitanRepository, $this->settingsConTemporada(), $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-bad-jwt' ) );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'no_autorizado', $response->get_data()['code'] );
        $this->assertTrue( $this->eventLog->has( 'rest.autorizacion_denegada' ) );
        $this->assertSame( InvalidTokenException::class, $this->eventLog->last()['contexto']['excepcion'] );
    }

    public function test_listar_returns_403_for_a_revoked_session(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willThrowException( new SessionRevokedException() );

        $capitanRepository = $this->createMock( CapitanRepository::class );
        $capitanRepository->expects( $this->never() )->method( 'listEquiposByCapitan' );

        $controller = new CapitanController( $authorizer, $capitanRepository, $this->settingsConTemporada(), $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-revoked-jwt' ) );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( SessionRevokedException::class, $this->eventLog->last()['contexto']['excepcion'] );
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    public function test_listar_returns_the_single_team_a_captain_captains(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $capitanRepository = $this->createMock( CapitanRepository::class );
        $capitanRepository->expects( $this->once() )
            ->method( 'listEquiposByCapitan' )
            ->with( self::SEASON_ID, self::PLAYER_ID )
            ->willReturn( [ 100 ] );

        $controller = new CapitanController( $authorizer, $capitanRepository, $this->settingsConTemporada(), $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt' ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( self::SEASON_ID, $data['season_id'] );
        $this->assertSame( self::PLAYER_ID, $data['player_id'] );
        $this->assertSame(
            [ [ 'team_id' => 100, 'nombre' => 'Equipo #100' ] ],
            $data['teams']
        );
    }

    /**
     * CapitanRepository::listEquiposByCapitan()'s own docblock is explicit
     * that the schema does not stop the same player from captaining more
     * than one team at once — this endpoint must not pretend otherwise.
     */
    public function test_listar_returns_every_team_when_the_player_captains_two(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $capitanRepository = $this->createMock( CapitanRepository::class );
        $capitanRepository->method( 'listEquiposByCapitan' )->willReturn( [ 100, 200 ] );

        $controller = new CapitanController( $authorizer, $capitanRepository, $this->settingsConTemporada(), $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [
                [ 'team_id' => 100, 'nombre' => 'Equipo #100' ],
                [ 'team_id' => 200, 'nombre' => 'Equipo #200' ],
            ],
            $response->get_data()['teams']
        );
    }

    /**
     * "Authenticated but captains nothing" is a legitimate state the app has
     * to render — a 200 with an empty list, NEVER a 403. The caller only
     * ever learns about themselves, so there is nothing to withhold.
     */
    public function test_listar_returns_200_with_empty_teams_when_the_player_captains_nothing(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $capitanRepository = $this->createMock( CapitanRepository::class );
        $capitanRepository->method( 'listEquiposByCapitan' )->willReturn( [] );

        $controller = new CapitanController( $authorizer, $capitanRepository, $this->settingsConTemporada(), $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $response->get_data()['teams'] );
    }

    // -------------------------------------------------------------------------
    // A failed read must surface as an ERROR, never as "captains nothing"
    // -------------------------------------------------------------------------

    public function test_listar_returns_500_when_the_read_fails_instead_of_reporting_empty_teams(): void {
        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'verifyIdentity' )->willReturn( [ 'player_id' => self::PLAYER_ID ] );

        $capitanRepository = $this->createMock( CapitanRepository::class );
        $capitanRepository->method( 'listEquiposByCapitan' )
            ->willThrowException( new \RuntimeException( 'simulated read failure' ) );

        $controller = new CapitanController( $authorizer, $capitanRepository, $this->settingsConTemporada(), $this->eventLog );

        $response = $controller->listar( $this->requestConToken( 'a-valid-jwt' ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertArrayNotHasKey( 'teams', $response->get_data() );
        $this->assertTrue( $this->eventLog->has( 'rest.mis_equipos_fallida' ) );
    }
}
