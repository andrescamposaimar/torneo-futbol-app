<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Rest;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Auth\CredencialAuthorizer;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Photo\Exception\ReencodeFailedException;
use EntreRedes\Credencial\Photo\MemoryGuard;
use EntreRedes\Credencial\Photo\PhotoReencoder;
use EntreRedes\Credencial\Photo\PhotoValidator;
use EntreRedes\Credencial\Photo\UploadBodyReader;
use EntreRedes\Credencial\Player\PlayerReader;
use EntreRedes\Credencial\Rest\PhotoUploadController;
use EntreRedes\Credencial\Tests\Support\FaultInjectingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Rest\PhotoUploadController — POST /entre-redes/v1/credencial/foto (design
 * D7/D9/D10). ApprovalRequestRepository and PlayerReader are REAL (both are
 * `final` — same "real collaborator when mockable is not possible" style as
 * CredencialControllerTest). PhotoReencoder is a small FAKE implementing the
 * interface — exactly the point of that interface (this class's own
 * docblock): a controller test can simulate a decode/re-encode failure
 * without ever crafting a real corrupt-image fixture. CredencialAuthorizer
 * is mocked (not `final`), same as CredencialControllerTest.
 */
class PhotoUploadControllerTest extends TestCase {

    private const NOW = 1_800_000_000;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );

        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador Uno', 'post_date' => '2000-01-01 00:00:00',
        ];
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_posts']   = [];
        $GLOBALS['wp_test_postmeta'] = [];

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );
    }

    private function fakeReencoder( ?string $returns = 'reencoded-jpeg-bytes', ?\Throwable $throws = null ): PhotoReencoder {
        return new class( $returns, $throws ) implements PhotoReencoder {
            public function __construct( private readonly ?string $returns, private readonly ?\Throwable $throws ) {
            }

            public function reencode( string $decodedBytes ): string {
                if ( null !== $this->throws ) {
                    throw $this->throws;
                }

                return $this->returns;
            }
        };
    }

    private function newController(
        CredencialAuthorizer $authorizer,
        ?PhotoReencoder $reencoder = null,
        ?InMemoryEventLog $eventLog = null,
        ?callable $memoryLimitFn = null,
        ?callable $memoryUsageFn = null
    ): PhotoUploadController {
        global $wpdb;

        return new PhotoUploadController(
            $authorizer,
            new PlayerReader(),
            new UploadBodyReader(),
            new PhotoValidator(),
            new MemoryGuard(),
            $reencoder ?? $this->fakeReencoder(),
            new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() ),
            $eventLog ?? new InMemoryEventLog(),
            static fn (): int => self::NOW,
            $memoryLimitFn,
            $memoryUsageFn
        );
    }

    private function authorizedRequest( array $params = [] ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer whatever' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }

        return $request;
    }

    private function authorizerFor( int $playerId, int $userId = 42 ): CredencialAuthorizer {
        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'user_id' => $userId, 'player_id' => $playerId ] );

        return $authorizer;
    }

    private function validBase64Jpeg(): string {
        $image = imagecreatetruecolor( 400, 400 );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 100, 100, 100 ) );
        ob_start();
        imagejpeg( $image, null, 90 );

        return base64_encode( (string) ob_get_clean() );
    }

    // -------------------------------------------------------------------------
    // Auth / eligibility
    // -------------------------------------------------------------------------

    public function test_authorization_failure_is_returned_unchanged(): void {
        $authorizer = $this->createMock( CredencialAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn(
            new \WP_Error( 'token_expired', 'Access token has expired.', [ 'status' => 401 ] )
        );

        $response = $this->newController( $authorizer )->upload( $this->authorizedRequest() );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'token_expired', $response->get_data()['code'] );
    }

    public function test_a_non_existent_player_is_rejected_with_403(): void {
        $response = $this->newController( $this->authorizerFor( 999 ) )->upload( $this->authorizedRequest() );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'not_a_player', $response->get_data()['code'] );
    }

    public function test_a_blocked_player_is_rejected_with_403(): void {
        $GLOBALS['wp_test_postmeta'][1]['estado'] = [ 'Inhabilitado' ];

        $response = $this->newController( $this->authorizerFor( 1 ) )->upload( $this->authorizedRequest() );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'blocked', $response->get_data()['code'] );
    }

    // -------------------------------------------------------------------------
    // Body / validator error paths (design D9)
    // -------------------------------------------------------------------------

    public function test_empty_body_returns_400(): void {
        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => '' ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'empty_body', $response->get_data()['code'] );
    }

    public function test_invalid_base64_returns_400(): void {
        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => 'not@@valid base64!!' ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_base64', $response->get_data()['code'] );
    }

    public function test_oversized_decoded_image_returns_413(): void {
        $tooBig = base64_encode( str_repeat( 'a', UploadBodyReader::MAX_DECODED_BYTES + 1 ) );

        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => $tooBig ] ) );

        $this->assertSame( 413, $response->get_status() );
        $this->assertSame( 'image_too_large', $response->get_data()['code'] );
    }

    public function test_bytes_that_are_not_an_image_return_400_invalid_image(): void {
        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => base64_encode( 'not an image' ) ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_image', $response->get_data()['code'] );
    }

    public function test_an_image_smaller_than_the_minimum_returns_422(): void {
        $image = imagecreatetruecolor( 100, 100 );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 1, 1, 1 ) );
        ob_start();
        imagejpeg( $image, null, 90 );
        $tooSmall = base64_encode( (string) ob_get_clean() );

        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => $tooSmall ] ) );

        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( 'image_dimensions', $response->get_data()['code'] );
    }

    // -------------------------------------------------------------------------
    // Memory guard (design D9)
    // -------------------------------------------------------------------------

    public function test_insufficient_memory_returns_500_and_logs_an_event(): void {
        $eventLog = new InMemoryEventLog();

        // Real MemoryGuard, real (small, valid) image — the fake limit
        // provider (same injectable-reader convention as
        // Migrations\MigrationRunner::checkRuntimeLimits()) is what forces
        // the guard to reject it, regardless of the real host's own memory.
        $response = $this->newController(
            $this->authorizerFor( 1 ),
            null,
            $eventLog,
            static fn (): string => '1M',
            static fn (): int => 0
        )->upload( $this->authorizedRequest( [ 'image_base64' => $this->validBase64Jpeg() ] ) );

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'insufficient_memory', $response->get_data()['code'] );
        $this->assertTrue( $eventLog->has( 'photo.insufficient_memory' ) );
    }

    // -------------------------------------------------------------------------
    // Re-encode failures — via the FAKE PhotoReencoder (this class's own docblock)
    // -------------------------------------------------------------------------

    public function test_a_reencode_failure_returns_400_invalid_image(): void {
        $reencoder = $this->fakeReencoder( null, new ReencodeFailedException() );

        $response = $this->newController( $this->authorizerFor( 1 ), $reencoder )
            ->upload( $this->authorizedRequest( [ 'image_base64' => $this->validBase64Jpeg() ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_image', $response->get_data()['code'] );
    }

    // -------------------------------------------------------------------------
    // Persistence — 202 / 409 already_pending / 500 (real ApprovalRequestRepository)
    // -------------------------------------------------------------------------

    public function test_a_successful_upload_creates_exactly_one_pending_request_and_returns_202(): void {
        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => $this->validBase64Jpeg() ] ) );

        $this->assertSame( 202, $response->get_status() );
        $this->assertSame( 'pending', $response->get_data()['status'] );
        $this->assertGreaterThan( 0, $response->get_data()['request_id'] );

        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}credencial_approval_request" );
        $this->assertSame( 1, $count );
    }

    /**
     * The `pending_key` UNIQUE index (InitialSchema::ensurePendingKeyIndex())
     * is a documented no-op under the SQLite test shim — only real MySQL
     * enforces it (see that method's own docblock). A second upload against
     * the shared shim would therefore just insert a second row, proving
     * nothing about this controller's own 409 mapping. FaultInjectingWpdb
     * scripts the real MySQL rejection a production duplicate would produce,
     * exactly like ApprovalRequestRepositoryTest does at the repository
     * level — this test is the controller-level counterpart.
     */
    public function test_uploading_while_a_request_is_already_pending_returns_409(): void {
        $wpdb = new FaultInjectingWpdb();
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_approval_request (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT, target_player_id INTEGER, requested_by INTEGER,
                payload TEXT, status TEXT, attachment_id INTEGER,
                review_note TEXT, reviewed_by INTEGER, reviewed_at TEXT, created_at TEXT
            )'
        );
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_approval_blob (
                request_id INTEGER PRIMARY KEY, photo_binary BLOB, created_at TEXT
            )'
        );
        $wpdb->failNextQueryMatching(
            '/INSERT INTO wp_credencial_approval_request/i',
            "Duplicate entry 'photo:1' for key 'uq_pending_key'"
        );

        $controller = new PhotoUploadController(
            $this->authorizerFor( 1 ),
            new PlayerReader(),
            new UploadBodyReader(),
            new PhotoValidator(),
            new MemoryGuard(),
            $this->fakeReencoder(),
            new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() ),
            new InMemoryEventLog(),
            static fn (): int => self::NOW
        );

        $response = $controller->upload( $this->authorizedRequest( [ 'image_base64' => $this->validBase64Jpeg() ] ) );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'already_pending', $response->get_data()['code'] );
    }

    public function test_upload_rate_limit_returns_429_after_5_uploads_in_24h(): void {
        global $wpdb;

        for ( $i = 0; $i < 5; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'credencial_approval_request', [
                'type'             => ApprovalRequestRepository::TYPE_PHOTO,
                'target_player_id' => 1,
                'requested_by'     => 42,
                'payload'          => '{}',
                'status'           => 'rejected', // decided, so it does not collide with pending_key
                'created_at'       => gmdate( 'Y-m-d H:i:s', self::NOW - 100 ),
            ] );
        }

        $response = $this->newController( $this->authorizerFor( 1 ) )
            ->upload( $this->authorizedRequest( [ 'image_base64' => $this->validBase64Jpeg() ] ) );

        $this->assertSame( 429, $response->get_status() );
        $this->assertSame( 'too_many_uploads', $response->get_data()['code'] );
    }

    public function test_register_routes_wires_the_post_foto_route(): void {
        unset( $GLOBALS['_prode_test_registered_routes'] );

        $this->newController( $this->authorizerFor( 1 ) )->register_routes();

        $routes = $GLOBALS['_prode_test_registered_routes'] ?? [];
        $match  = array_filter(
            $routes,
            static fn ( array $r ): bool => 'entre-redes/v1' === $r['namespace']
                && '/credencial/foto' === $r['route']
                && \WP_REST_Server::CREATABLE === ( $r['args']['methods'] ?? null )
        );

        $this->assertNotEmpty( $match, 'POST /entre-redes/v1/credencial/foto must be registered.' );
    }
}
