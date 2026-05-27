<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Account;

use EntreRedes\Prode\Account\AccountController;
use EntreRedes\Prode\Audit\AuditLogger;
use EntreRedes\Prode\Audit\DniHasher;
use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Auth\JwtService;
use EntreRedes\Prode\Auth\SessionManager;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the account deletion endpoint.
 *
 * Covers (high-value correctness cases):
 *   1. Soft-delete sets deleted_at, deleted_by='user'
 *   2. PII anonymization: email and display_name nullified, DNI preserved
 *   3. session_version increments (revokeAllSessions) after deletion
 *   4. All refresh tokens revoked after deletion
 *   5. prode_associations soft-deleted after deletion
 *   6. Audit log entry written with account_deletion event type + prode_user_id
 *   7. Idempotency: calling DELETE twice → first call 200, second call 401 (auth wall)
 *   8. Re-onboarding: after deletion, findByProviderAssociation returns null → new user flow
 *
 * These tests use the SQLite WP shim (tests/wp-shim.php) and exercise the full
 * AccountController::handleDelete() logic without a MySQL instance.
 */
class AccountDeletionTest extends TestCase {

    private SessionManager     $session;
    private AuditLogger        $audit;
    private DniHasher          $hasher;
    private AccountController  $controller;

    // Test user constants.
    private const USER_ID     = 42;
    private const PLAYER_ID   = 99;
    private const DNI         = '87654321';
    private const EMAIL       = 'delete-me@example.com';
    private const DISPLAY     = 'Delete Me User';
    private const PROVIDER    = 'google';
    private const PROVIDER_ID = 'google_sub_delete_test';

    protected function setUp(): void {
        InitialSchema::up();
        $this->seedTestUserAndAssociation();

        $this->session    = new SessionManager();
        $this->audit      = new AuditLogger();
        $this->hasher     = new DniHasher();

        // Build a real JwtService for the middleware (needed for WP_REST_Request parameter injection).
        $jwt    = new JwtService();
        $middleware = new AuthMiddleware( $jwt, $this->session );

        $this->controller = new AccountController(
            $middleware,
            $this->session,
            $this->audit,
            $this->hasher
        );
    }

    protected function tearDown(): void {
        global $wpdb;
        // Clean up between tests so each test gets a fresh state.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_associations WHERE user_id = " . self::USER_ID );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = " . self::USER_ID );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_audit_log" );
    }

    // -------------------------------------------------------------------------
    // 1. Soft-delete marks deleted_at + deleted_by='user'
    // -------------------------------------------------------------------------

    public function test_soft_delete_sets_deleted_at_and_deleted_by(): void {
        global $wpdb;

        $request = $this->buildAuthedRequest();
        $response = $this->controller->handleDelete( $request );

        $this->assertSame( 200, $response->get_status() );

        $row = $wpdb->get_row(
            "SELECT deleted_at, deleted_by FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID,
            ARRAY_A
        );

        $this->assertNotNull( $row['deleted_at'], 'deleted_at must be set after deletion' );
        $this->assertSame( 'user', $row['deleted_by'], 'deleted_by must be "user" for user-initiated deletion' );
    }

    // -------------------------------------------------------------------------
    // 2. PII anonymization: email + display_name nullified; DNI preserved
    // -------------------------------------------------------------------------

    public function test_email_and_display_name_are_anonymized_after_deletion(): void {
        global $wpdb;

        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        $row = $wpdb->get_row(
            "SELECT email, display_name, dni FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID,
            ARRAY_A
        );

        // email is set to NULL (column allows null — Ley 25.326 PII erasure).
        $this->assertNull( $row['email'], 'email must be set to NULL after deletion (PII anonymization)' );

        // display_name is set to '' (column is NOT NULL, so empty string is the
        // anonymized tombstone value). The UI renders '' as "[deleted]".
        $this->assertSame( '', $row['display_name'], 'display_name must be emptied after deletion (PII anonymization)' );
    }

    public function test_dni_is_preserved_as_tombstone(): void {
        global $wpdb;

        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        $dni = $wpdb->get_var(
            "SELECT dni FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );

        // DNI is kept for tombstone integrity (re-onboarding prevention + audit trail).
        $this->assertSame( self::DNI, $dni, 'DNI must be preserved as tombstone after deletion' );
    }

    // -------------------------------------------------------------------------
    // 3. session_version increments after deletion
    // -------------------------------------------------------------------------

    public function test_session_version_increments_after_deletion(): void {
        global $wpdb;

        $sv_before = (int) $wpdb->get_var(
            "SELECT session_version FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );
        $this->assertSame( 1, $sv_before );

        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        // After deletion, the user row has deleted_at set, so getUserSessionVersion
        // returns null. We query directly to verify the bump happened.
        $sv_after = (int) $wpdb->get_var(
            "SELECT session_version FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );
        $this->assertGreaterThan( $sv_before, $sv_after, 'session_version must increment after deletion' );
    }

    // -------------------------------------------------------------------------
    // 4. Refresh tokens purged after deletion
    // -------------------------------------------------------------------------

    public function test_refresh_tokens_are_purged_after_deletion(): void {
        global $wpdb;

        // Issue two tokens before deletion.
        $this->session->issueRefreshToken( self::USER_ID );
        $this->session->issueRefreshToken( self::USER_ID );

        $count_before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = " . self::USER_ID
        );
        $this->assertSame( 2, $count_before );

        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        $count_after = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = " . self::USER_ID
        );
        $this->assertSame( 0, $count_after, 'All refresh tokens must be purged after deletion' );
    }

    // -------------------------------------------------------------------------
    // 5. prode_associations soft-deleted
    // -------------------------------------------------------------------------

    public function test_associations_are_soft_deleted_after_account_deletion(): void {
        global $wpdb;

        $active_before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_associations
              WHERE user_id = " . self::USER_ID . " AND deleted_at IS NULL"
        );
        $this->assertSame( 1, $active_before );

        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        $active_after = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_associations
              WHERE user_id = " . self::USER_ID . " AND deleted_at IS NULL"
        );
        $this->assertSame( 0, $active_after, 'All active associations must be soft-deleted' );
    }

    // -------------------------------------------------------------------------
    // 6. Audit log entry written
    // -------------------------------------------------------------------------

    public function test_audit_log_entry_is_written_after_deletion(): void {
        global $wpdb;

        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        $row = $wpdb->get_row(
            "SELECT event_type, metadata_json, dni_hash, provider
               FROM {$wpdb->prefix}prode_audit_log
              WHERE event_type = 'user_account_deletion'
              LIMIT 1",
            ARRAY_A
        );

        $this->assertNotNull( $row, 'Audit log entry must be written after account deletion' );
        $this->assertSame( 'user_account_deletion', $row['event_type'] );
        $this->assertSame( self::PROVIDER, $row['provider'] );

        // Verify prode_user_id is stored in metadata_json.
        $meta = json_decode( (string) $row['metadata_json'], true );
        $this->assertSame( self::USER_ID, $meta['prode_user_id'] );
        $this->assertSame( 'self', $meta['actor'] );

        // Verify DNI hash matches.
        $expected_hash = $this->hasher->hash( self::DNI );
        $this->assertSame( $expected_hash, $row['dni_hash'] );
    }

    // -------------------------------------------------------------------------
    // 7. Idempotency: second call is blocked by auth wall
    // -------------------------------------------------------------------------

    public function test_second_deletion_call_is_rejected_by_auth_middleware(): void {
        // First call: succeeds (user is active).
        $session = new SessionManager();

        // Directly test the auth layer: after deletion, getUser() returns null,
        // which means AuthMiddleware::requireAuth() returns 401. We simulate this
        // by verifying getUserSessionVersion returns null after deletion.
        $sv_before = $session->getUserSessionVersion( self::USER_ID );
        $this->assertNotNull( $sv_before );

        $request = $this->buildAuthedRequest();
        $response = $this->controller->handleDelete( $request );
        $this->assertSame( 200, $response->get_status() );

        // After deletion, getUser() returns null (deleted_at IS NOT NULL filters it out).
        $user_after = $session->getUser( self::USER_ID );
        $this->assertNull( $user_after, 'getUser() must return null after soft-delete' );

        // And getUserSessionVersion also returns null.
        $sv_after = $session->getUserSessionVersion( self::USER_ID );
        $this->assertNull( $sv_after, 'getUserSessionVersion() must return null for a deleted user' );
    }

    // -------------------------------------------------------------------------
    // 8. Re-onboarding: after deletion, findByProviderAssociation returns null
    // -------------------------------------------------------------------------

    public function test_re_onboarding_finds_no_association_after_deletion(): void {
        $request = $this->buildAuthedRequest();
        $this->controller->handleDelete( $request );

        // Simulate the SSO step: look up by provider + provider_id.
        // Must return null so the user is treated as new → fresh registration flow.
        $found = $this->session->findByProviderAssociation( self::PROVIDER, self::PROVIDER_ID );
        $this->assertNull( $found, 'findByProviderAssociation must return null after user deletion (re-onboarding as new user)' );
    }

    // -------------------------------------------------------------------------
    // 9. Audit best-effort: entry written even without active association (W2)
    // -------------------------------------------------------------------------

    public function test_audit_entry_written_when_association_is_missing(): void {
        global $wpdb;

        // Edge case: an admin previously hard-deleted the association rows for
        // this user. Per Ley 25.326, the deletion audit entry must still be
        // written — with whatever fields are available — instead of being
        // silently skipped.
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}prode_associations WHERE user_id = " . self::USER_ID
        );

        $request  = $this->buildAuthedRequest();
        $response = $this->controller->handleDelete( $request );
        $this->assertSame( 200, $response->get_status() );

        $row = $wpdb->get_row(
            "SELECT event_type, metadata_json, dni_hash, provider
               FROM {$wpdb->prefix}prode_audit_log
              WHERE event_type = 'user_account_deletion'
              LIMIT 1",
            ARRAY_A
        );

        $this->assertNotNull( $row, 'Audit entry must be written even without active association' );
        $this->assertSame( 'user_account_deletion', $row['event_type'] );

        $meta = json_decode( (string) $row['metadata_json'], true );
        $this->assertSame( self::USER_ID, $meta['prode_user_id'] );
        $this->assertSame( 'self', $meta['actor'] );

        // dni_hash is still derivable from the JWT-attached _prode_user param,
        // so it must be present.
        $expected_hash = $this->hasher->hash( self::DNI );
        $this->assertSame( $expected_hash, $row['dni_hash'] );

        // provider has no source without an association → must be omitted from the row.
        $this->assertTrue(
            null === $row['provider'] || '' === $row['provider'],
            'provider must be absent when no association exists'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a WP_REST_Request pre-populated with the _prode_user param,
     * simulating what AuthMiddleware::requireAuth() injects after token validation.
     *
     * In real execution, requireAuth() validates the JWT and loads the user row.
     * Here we bypass JWT validation and inject the user row directly, which is
     * the correct pattern for unit-testing the handler logic in isolation.
     */
    private function buildAuthedRequest(): \WP_REST_Request {
        $request = new \WP_REST_Request();

        // Inject the user row that AuthMiddleware would attach.
        $request->set_param( '_prode_user', [
            'id'              => self::USER_ID,
            'tenant_id'       => 'marianista',
            'dni'             => self::DNI,
            'email'           => self::EMAIL,
            'display_name'    => self::DISPLAY,
            'session_version' => 1,
        ] );

        return $request;
    }

    /**
     * Seeds a prode_users row + prode_associations row for the test user.
     * Uses the same pattern as SessionManagerTest::seedTestUser().
     */
    private function seedTestUserAndAssociation(): void {
        global $wpdb;

        $exists = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );
        if ( $exists > 0 ) {
            return;
        }

        $now = current_time( 'mysql' );

        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'              => self::USER_ID,
                'tenant_id'       => 'marianista',
                'dni'             => self::DNI,
                'email'           => self::EMAIL,
                'provider'        => self::PROVIDER,
                'provider_id'     => self::PROVIDER_ID,
                'display_name'    => self::DISPLAY,
                'session_version' => 1,
                'created_at'      => $now,
            ]
        );

        $wpdb->insert(
            $wpdb->prefix . 'prode_associations',
            [
                'user_id'     => self::USER_ID,
                'provider'    => self::PROVIDER,
                'provider_id' => self::PROVIDER_ID,
                'dni'         => self::DNI,
                'player_id'   => self::PLAYER_ID,
                'created_at'  => $now,
            ]
        );
    }
}
