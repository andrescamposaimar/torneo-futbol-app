<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CambiosVigentesReader;
use EntreRedes\Cambios\Rest\VigentesController;
use PHPUnit\Framework\TestCase;

/**
 * The route is PUBLIC and read-only by product decision, so there is no
 * 401/403 to test. What matters: it answers 200 with the reader's rows for
 * the CONFIGURED season — never for one the client asks for — and a failed
 * read (or a broken season setting) is a 500 instead of an empty list.
 */
class VigentesControllerTest extends TestCase {

    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        $this->eventLog = new InMemoryEventLog();
    }

    private function settings( int $seasonId ): Settings {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'seasonId' )->willReturn( $seasonId );

        return $settings;
    }

    /** @param array<string, mixed> $params */
    private function request( array $params = [] ): \WP_REST_Request {
        $request = new \WP_REST_Request();

        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }

        return $request;
    }

    public function test_listar_returns_200_with_every_row_of_the_configured_season_and_needs_no_token(): void {
        $rows = [ [ 'team_id' => 100, 'plaza_id' => 1 ], [ 'team_id' => 200, 'plaza_id' => 2 ] ];

        $reader = $this->createMock( CambiosVigentesReader::class );
        $reader->expects( $this->once() )->method( 'listarPublico' )->with( 359 )->willReturn( $rows );

        $response = ( new VigentesController( $this->settings( 359 ), $reader, $this->eventLog ) )->listar( $this->request() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'season_id' => 359, 'vigentes' => $rows ], $response->get_data() );
    }

    public function test_listar_ignores_a_season_id_sent_by_the_client(): void {
        $reader = $this->createMock( CambiosVigentesReader::class );
        $reader->expects( $this->once() )->method( 'listarPublico' )->with( 359 )->willReturn( [] );

        $response = ( new VigentesController( $this->settings( 359 ), $reader, $this->eventLog ) )
            ->listar( $this->request( [ 'season_id' => '120' ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 359, $response->get_data()['season_id'] );
    }

    public function test_listar_returns_500_without_reading_when_the_configured_season_is_invalid(): void {
        $reader = $this->createMock( CambiosVigentesReader::class );
        $reader->expects( $this->never() )->method( 'listarPublico' );

        $response = ( new VigentesController( $this->settings( 0 ), $reader, $this->eventLog ) )->listar( $this->request() );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertTrue( $this->eventLog->has( 'rest.vigentes_fallida' ) );
    }

    public function test_listar_returns_500_when_the_read_fails_instead_of_an_empty_list(): void {
        $reader = $this->createMock( CambiosVigentesReader::class );
        $reader->method( 'listarPublico' )->willThrowException( new \RuntimeException( 'simulated read failure' ) );

        $response = ( new VigentesController( $this->settings( 359 ), $reader, $this->eventLog ) )->listar( $this->request() );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertArrayNotHasKey( 'vigentes', $response->get_data() );
        $this->assertTrue( $this->eventLog->has( 'rest.vigentes_fallida' ) );
    }
}
