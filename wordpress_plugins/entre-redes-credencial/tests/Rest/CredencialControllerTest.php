<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Rest;

use EntreRedes\Credencial\Auth\CredencialAuthorizer;
use EntreRedes\Credencial\Credencial\CredencialService;
use EntreRedes\Credencial\Credencial\IssuanceRepository;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Photo\GdPhotoReencoder;
use EntreRedes\Credencial\Photo\MemoryGuard;
use EntreRedes\Credencial\Photo\PhotoValidator;
use EntreRedes\Credencial\Photo\UploadBodyReader;
use EntreRedes\Credencial\Player\PlayerReader;
use EntreRedes\Credencial\Player\TeamResolver;
use EntreRedes\Credencial\Rest\CredencialController;
use EntreRedes\Credencial\Rest\PhotoUploadController;
use EntreRedes\Credencial\Rest\RestController;
use EntreRedes\Credencial\Tests\Support\FaultInjectingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Rest\CredencialController — GET /entre-redes/v1/credencial/credencial.
 *
 * CredencialAuthorizer is mocked (it is not `final` — see design D1): this
 * is what lets the "every WP_Error the authorizer returns passes straight
 * through, unchanged" test force each of the 4 auth codes deterministically.
 * CredencialService IS real (it is `final`) — same "real collaborator when
 * mockable is not possible" style as entre-redes-cambios's own
 * SolicitudesControllerTest with DictamenPipeline.
 */
class CredencialControllerTest extends TestCase {

    private const NOW = 1_800_000_000;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_posts']              = [];
        $GLOBALS['wp_test_postmeta']            = [];
        $GLOBALS['wp_test_post_thumbnail_urls'] = [];

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
    }

    private function fakeTeamResolver(): TeamResolver {
        return new class() implements TeamResolver {
            public function resolve( int $playerId ): ?array {
                return null;
            }
        };
    }

    private function realService(): CredencialService {
        global $wpdb;

        return new CredencialService(
            new PlayerReader(),
            $this->fakeTeamResolver(),
            new IssuanceRepository( $wpdb, new InMemoryEventLog() ),
            new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() ),
            'test-secret'
        );
    }

    private function newController( CredencialAuthorizer $authorizer, ?CredencialService $service = null, ?InMemoryEventLog $eventLog = null ): CredencialController {
        return new CredencialController(
            $authorizer,
            $service ?? $this->realService(),
            $eventLog ?? new InMemoryEventLog(),
            static fn (): int => self::NOW
        );
    }

    public function test_authorization_failure_is_returned_unchanged_as_the_response(): void {
        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn(
            new \WP_Error( 'token_expired', 'Access token has expired.', [ 'status' => 401 ] )
        );

        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer whatever' );

        $response = $this->newController( $authorizer )->get( $request );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'token_expired', $response->get_data()['code'] );
    }

    public function test_eligible_player_with_photo_returns_active_state(): void {
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador Uno', 'post_date' => '2000-01-01 00:00:00',
        ];
        $GLOBALS['wp_test_postmeta'][1]['dni']      = [ '30111222' ];
        $GLOBALS['wp_test_post_thumbnail_urls'][1]  = 'https://example.com/photo.jpg';

        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'user_id' => 42, 'player_id' => 1 ] );

        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer whatever' );

        $response = $this->newController( $authorizer )->get( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'active', $response->get_data()['state'] );
        $this->assertSame( 1, $response->get_data()['credential']['player_id'] );
    }

    public function test_not_a_player_returns_200_with_not_a_player_state(): void {
        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'user_id' => 42, 'player_id' => 999 ] );

        $request = new \WP_REST_Request();

        $response = $this->newController( $authorizer )->get( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'not_a_player', $response->get_data()['state'] );
    }

    public function test_unexpected_service_failure_returns_a_generic_500_and_logs_it(): void {
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador Uno', 'post_date' => '2000-01-01 00:00:00',
        ];
        $GLOBALS['wp_test_post_thumbnail_urls'][1] = 'https://example.com/photo.jpg';

        $wpdb = new FaultInjectingWpdb();
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_issuance (
                player_id INTEGER PRIMARY KEY, credential_id TEXT, user_id INTEGER,
                photo_sha256 TEXT, minted_at TEXT, updated_at TEXT
            )'
        );
        $wpdb->failNextQueryMatching( '/INSERT INTO wp_credencial_issuance/i', 'Deadlock found' );

        $service = new CredencialService(
            new PlayerReader(),
            $this->fakeTeamResolver(),
            new IssuanceRepository( $wpdb, new InMemoryEventLog() ),
            new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() ),
            'test-secret'
        );

        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'user_id' => 42, 'player_id' => 1 ] );

        $eventLog = new InMemoryEventLog();
        $request  = new \WP_REST_Request();

        $response = $this->newController( $authorizer, $service, $eventLog )->get( $request );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'error_interno', $response->get_data()['code'] );
        $this->assertTrue( $eventLog->has( 'rest.credencial_get_failed' ) );
    }

    public function test_register_routes_wires_the_get_credencial_route(): void {
        unset( $GLOBALS['_prode_test_registered_routes'] );

        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $controller = $this->newController( $authorizer );

        ( new RestController( $controller, $this->minimalPhotoUploadController( $authorizer ) ) )->register_routes();

        $routes = $GLOBALS['_prode_test_registered_routes'] ?? [];
        $match  = array_filter(
            $routes,
            static fn ( array $r ): bool => 'entre-redes/v1' === $r['namespace']
                && '/credencial/credencial' === $r['route']
                && \WP_REST_Server::READABLE === ( $r['args']['methods'] ?? null )
        );

        $this->assertNotEmpty( $match, 'GET /entre-redes/v1/credencial/credencial must be registered.' );
    }

    /**
     * RestController now wires BOTH routes together — a minimal, otherwise
     * uninteresting PhotoUploadController is enough here since this test
     * only asserts the GET route made it through; PhotoUploadControllerTest
     * owns its own behavior in depth.
     */
    private function minimalPhotoUploadController( CredencialAuthorizer $authorizer ): PhotoUploadController {
        global $wpdb;

        return new PhotoUploadController(
            $authorizer,
            new PlayerReader(),
            new UploadBodyReader(),
            new PhotoValidator(),
            new MemoryGuard(),
            new GdPhotoReencoder(),
            new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() ),
            new InMemoryEventLog()
        );
    }
}
