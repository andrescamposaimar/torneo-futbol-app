<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Credencial;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Code\RotatingCode;
use EntreRedes\Credencial\Credencial\CredencialService;
use EntreRedes\Credencial\Credencial\IssuanceRepository;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Player\PlayerReader;
use EntreRedes\Credencial\Player\TeamResolver;
use PHPUnit\Framework\TestCase;

/**
 * CredencialService orchestrates PlayerReader + TeamResolver +
 * IssuanceRepository + RotatingCode into the GET response's `state` (design
 * D14/D15, spec "Eligibility Determination" / "Approved Photo Gate" /
 * "Credential Payload and Display" / "Rotating Liveness Code").
 *
 * Uses the REAL PlayerReader and IssuanceRepository against the shared
 * SQLite shim (same convention as entre-redes-cambios's controller tests —
 * repositories are exercised for real, only TeamResolver, the one seam this
 * plugin does not own the implementation of, is faked).
 */
class CredencialServiceTest extends TestCase {

    private const SECRET = 'test-secret';
    private const NOW    = 1_800_000_000;

    private CredencialService $service;
    private ApprovalRequestRepository $approvalRequests;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );

        $this->approvalRequests = new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() );

        $this->service = new CredencialService(
            new PlayerReader(),
            $this->fakeTeamResolver( [ 'id' => 5, 'name' => 'Boca Juniors' ] ),
            new IssuanceRepository( $wpdb, new InMemoryEventLog() ),
            $this->approvalRequests,
            self::SECRET
        );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_posts']               = [];
        $GLOBALS['wp_test_postmeta']             = [];
        $GLOBALS['wp_test_post_thumbnail_urls']  = [];
        $GLOBALS['wp_test_post_thumbnail_ids']   = [];

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );
    }

    /** Inserts an already-approved photo request directly, bypassing the pending flow. */
    private function approvedPhotoRequest( int $playerId, int $attachmentId, int $reviewedAtEpoch ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'credencial_approval_request', [
            'type'             => ApprovalRequestRepository::TYPE_PHOTO,
            'target_player_id' => $playerId,
            'requested_by'     => 100,
            'payload'          => '{}',
            'status'           => 'approved',
            'attachment_id'    => $attachmentId,
            'reviewed_at'      => gmdate( 'Y-m-d H:i:s', $reviewedAtEpoch ),
            'created_at'       => gmdate( 'Y-m-d H:i:s', $reviewedAtEpoch ),
        ] );

        return (int) $wpdb->insert_id;
    }

    private function fakeTeamResolver( ?array $team ): TeamResolver {
        return new class( $team ) implements TeamResolver {
            public function __construct( private readonly ?array $team ) {
            }

            public function resolve( int $playerId ): ?array {
                return $this->team;
            }
        };
    }

    private function seedEligiblePlayerWithPhoto( int $id, array $post = [], array $meta = [], int $thumbnailId = 55 ): void {
        $GLOBALS['wp_test_posts'][ $id ] = array_merge(
            [ 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador ' . $id, 'post_date' => '2000-01-01 00:00:00' ],
            $post
        );
        foreach ( array_merge( [ 'dni' => '30111222', 'caracter' => 'Padre Alumno' ], $meta ) as $key => $value ) {
            $GLOBALS['wp_test_postmeta'][ $id ][ $key ] = [ $value ];
        }
        $GLOBALS['wp_test_post_thumbnail_urls'][ $id ] = 'https://example.com/photo.jpg';
        $GLOBALS['wp_test_post_thumbnail_ids'][ $id ]  = $thumbnailId;
    }

    public function test_no_matching_sp_player_is_not_a_player(): void {
        $state = $this->service->resolve( 999, 1, self::NOW );

        $this->assertSame( 'not_a_player', $state->toArray()['state'] );
    }

    public function test_blocked_estado_returns_blocked_with_no_credential(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [], [ 'estado' => 'Inhabilitado' ] );

        $state = $this->service->resolve( 1, 1, self::NOW )->toArray();

        $this->assertSame( 'blocked', $state['state'] );
        $this->assertNull( $state['credential'] );
    }

    public function test_eligible_without_a_photo_is_no_photo(): void {
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador 1', 'post_date' => '2000-01-01 00:00:00',
        ];
        // Deliberately no wp_test_post_thumbnail_urls[1] / ids[1] set.

        $state = $this->service->resolve( 1, 1, self::NOW )->toArray();

        $this->assertSame( 'no_photo', $state['state'] );
        $this->assertNull( $state['credential'] );
    }

    /**
     * Photo gate (design rev 9 Interfaces): thumbnail id AND url are BOTH
     * required. A URL without a resolvable attachment id (should not happen
     * in real WordPress, but the gate must not trust the URL alone) is
     * treated as no_photo.
     */
    public function test_url_present_but_no_thumbnail_id_is_no_photo(): void {
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador 1', 'post_date' => '2000-01-01 00:00:00',
        ];
        $GLOBALS['wp_test_post_thumbnail_urls'][1] = 'https://example.com/photo.jpg';
        // Deliberately no wp_test_post_thumbnail_ids[1] set.

        $state = $this->service->resolve( 1, 1, self::NOW )->toArray();

        $this->assertSame( 'no_photo', $state['state'] );
        $this->assertNull( $state['credential'] );
    }

    public function test_active_player_gets_a_full_credential_payload(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $credential = $state['credential'];

        $this->assertSame( 1, $credential['player_id'] );
        $this->assertSame( 'Jugador 1', $credential['full_name'] );
        $this->assertSame( '30111222', $credential['dni'] );
        $this->assertSame( 'Padre Alumno', $credential['caracter'] );
        $this->assertSame( [ 'id' => 55, 'url' => 'https://example.com/photo.jpg' ], $credential['photo'] );
        $this->assertSame( [ 'id' => 5, 'name' => 'Boca Juniors', 'kind' => 'team' ], $credential['team'] );
        $this->assertSame(
            [ 'alg' => RotatingCode::ALG, 'step' => RotatingCode::STEP, 'digits' => RotatingCode::DIGITS ],
            $credential['code']
        );
        $this->assertSame(
            RotatingCode::seedFor( self::SECRET, $credential['id'] ),
            $credential['code_seed']
        );
    }

    public function test_credential_id_is_stable_across_repeated_resolutions(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );

        $first  = $this->service->resolve( 1, 42, self::NOW )->toArray()['credential']['id'];
        $second = $this->service->resolve( 1, 42, self::NOW + 30 )->toArray()['credential']['id'];

        $this->assertSame( $first, $second );
    }

    public function test_credential_id_rotates_when_the_thumbnail_attachment_id_changes(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [], [], 55 );
        $first = $this->service->resolve( 1, 42, self::NOW )->toArray()['credential']['id'];

        $this->seedEligiblePlayerWithPhoto( 1, [], [], 56 );
        $second = $this->service->resolve( 1, 42, self::NOW + 30 )->toArray()['credential']['id'];

        $this->assertNotSame( $first, $second );
    }

    public function test_team_unavailable_renders_credential_without_a_team_badge(): void {
        global $wpdb;

        $this->service = new CredencialService(
            new PlayerReader(),
            $this->fakeTeamResolver( null ),
            new IssuanceRepository( $wpdb, new InMemoryEventLog() ),
            $this->approvalRequests,
            self::SECRET
        );
        $this->seedEligiblePlayerWithPhoto( 1 );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['credential']['team'] );
    }

    public function test_empty_caracter_renders_without_failing(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [], [ 'caracter' => '' ] );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['credential']['caracter'] );
    }

    public function test_out_of_range_birth_date_renders_null_without_failing(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [ 'post_date' => '2020-01-01 00:00:00' ] ); // < 18yo at self::NOW

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['credential']['birth_date'] );
    }

    // -------------------------------------------------------------------------
    // engram 1589: `photo_request` was always null — ApprovalRequestRepository
    // was never wired into resolve(). These prove the wiring, not just the
    // repository queries (already covered by ApprovalRequestRepositoryTest).
    // -------------------------------------------------------------------------

    public function test_no_photo_with_a_pending_upload_reports_pending_photo_request(): void {
        // Deliberately eligible but with no approved photo yet (spec "First upload").
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador 1', 'post_date' => '2000-01-01 00:00:00',
        ];
        $requestId = $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'no_photo', $state['state'] );
        $this->assertSame(
            [ 'id' => $requestId, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW ) ],
            $state['photo_request']
        );
    }

    public function test_active_player_with_a_pending_replacement_reports_pending_photo_request(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );
        $requestId = $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNotNull( $state['credential'], 'the old approved photo must keep backing the credential while a replacement is pending' );
        $this->assertSame(
            [ 'id' => $requestId, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW ) ],
            $state['photo_request']
        );
    }

    public function test_rejected_request_newer_than_the_approved_photo_is_reported(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );
        $this->approvedPhotoRequest( 1, 55, self::NOW - 3600 );

        $requestId = $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW - 1800 );
        $this->approvalRequests->rejectPending( $requestId, 1, 'no se ve la cara', self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertSame(
            [ 'id' => $requestId, 'status' => 'rejected', 'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 1800 ) ],
            $state['photo_request']
        );
    }

    public function test_rejected_request_older_than_the_approved_photo_is_not_reported(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );

        $requestId = $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW - 3600 );
        $this->approvalRequests->rejectPending( $requestId, 1, 'no se ve la cara', self::NOW - 1800 );

        // A LATER approval supersedes the earlier rejection.
        $this->approvedPhotoRequest( 1, 55, self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['photo_request'] );
    }

    public function test_first_upload_rejected_with_no_approved_photo_ever_is_reported(): void {
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador 1', 'post_date' => '2000-01-01 00:00:00',
        ];

        $requestId = $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW - 1800 );
        $this->approvalRequests->rejectPending( $requestId, 1, 'no se ve la cara', self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'no_photo', $state['state'] );
        $this->assertSame(
            [ 'id' => $requestId, 'status' => 'rejected', 'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 1800 ) ],
            $state['photo_request']
        );
    }

    public function test_no_pending_and_no_rejected_reports_no_photo_request(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );
        $this->approvedPhotoRequest( 1, 55, self::NOW - 3600 );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['photo_request'] );
    }

    public function test_approved_but_unpublished_request_is_not_reported(): void {
        // Design: "An approved-but-unpublished request is not reported; the
        // card shows the currently published photo." The live thumbnail
        // (seeded by seedEligiblePlayerWithPhoto) never matches attachment_id
        // 999, so this approved request is deliberately still unpublished.
        $this->seedEligiblePlayerWithPhoto( 1 );
        $this->approvedPhotoRequest( 1, 999, self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['photo_request'] );
    }

    public function test_blocked_player_never_leaks_a_pending_photo_request(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [], [ 'estado' => 'Inhabilitado' ] );
        $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'blocked', $state['state'] );
        $this->assertNull( $state['photo_request'] );
    }

    public function test_not_a_player_never_leaks_a_pending_photo_request(): void {
        // No sp_player post at all for id 1, but a stray approval row exists.
        $this->approvalRequests->createPendingPhotoRequest( 1, 42, 'bytes', self::NOW );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'not_a_player', $state['state'] );
        $this->assertNull( $state['photo_request'] );
    }
}
