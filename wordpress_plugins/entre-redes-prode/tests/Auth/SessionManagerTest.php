<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Auth;

use EntreRedes\Prode\Auth\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SessionManager.
 *
 * Covers:
 *   - Refresh token issuance: token is a UUID string, stored as hash
 *   - Refresh token rotation: old token revoked, new token returned
 *   - Replayed refresh token rejected after rotation
 *   - revokeAllSessions: increments session_version, purges all refresh tokens
 *   - getUserSessionVersion returns null for soft-deleted users
 *
 * These tests use the SQLite WP shim (tests/wp-shim.php) to exercise real
 * DB logic without a MySQL instance.
 */
class SessionManagerTest extends TestCase {

    private SessionManager $manager;

    protected function setUp(): void {
        // Ensure tables exist in the shim's in-memory SQLite DB.
        \EntreRedes\Prode\Migrations\InitialSchema::up();
        $this->seedTestUser();
        $this->manager = new SessionManager();
    }

    protected function tearDown(): void {
        global $wpdb;
        // Clean up tokens and users between tests.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_refresh_tokens" );
    }

    // -------------------------------------------------------------------------
    // Refresh token issuance
    // -------------------------------------------------------------------------

    public function test_issue_refresh_token_returns_non_empty_string(): void {
        $token = $this->manager->issueRefreshToken( 1 );
        $this->assertIsString( $token );
        $this->assertNotEmpty( $token );
        // A UUID v4 is 36 chars.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $token
        );
    }

    public function test_issued_token_is_stored_as_hash(): void {
        global $wpdb;
        $token = $this->manager->issueRefreshToken( 1 );
        $hash  = hash( 'sha256', $token );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_refresh_tokens WHERE token_hash = %s",
                $hash
            ),
            ARRAY_A
        );

        $this->assertNotNull( $row );
        $this->assertNull( $row['revoked_at'] );
    }

    // -------------------------------------------------------------------------
    // Refresh token rotation
    // -------------------------------------------------------------------------

    public function test_rotate_refresh_token_returns_new_token(): void {
        $old_token = $this->manager->issueRefreshToken( 1 );
        $result    = $this->manager->rotateRefreshToken( $old_token );

        $this->assertArrayHasKey( 'token', $result );
        $this->assertArrayHasKey( 'user_id', $result );
        $this->assertNotSame( $old_token, $result['token'] );
        $this->assertSame( 1, $result['user_id'] );
    }

    public function test_old_token_is_revoked_after_rotation(): void {
        global $wpdb;
        $old_token  = $this->manager->issueRefreshToken( 1 );
        $old_hash   = hash( 'sha256', $old_token );

        $this->manager->rotateRefreshToken( $old_token );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT revoked_at FROM {$wpdb->prefix}prode_refresh_tokens WHERE token_hash = %s",
                $old_hash
            ),
            ARRAY_A
        );

        $this->assertNotNull( $row['revoked_at'] );
    }

    public function test_replayed_token_rejected_after_rotation(): void {
        $token = $this->manager->issueRefreshToken( 1 );
        $this->manager->rotateRefreshToken( $token );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'refresh_token_invalid' );

        // Replaying the old token must fail.
        $this->manager->rotateRefreshToken( $token );
    }

    public function test_nonexistent_token_rejected(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'refresh_token_invalid' );

        $this->manager->rotateRefreshToken( 'completely-invalid-token-value' );
    }

    // -------------------------------------------------------------------------
    // Session revocation
    // -------------------------------------------------------------------------

    public function test_revoke_all_sessions_increments_session_version(): void {
        $sv_before = $this->manager->getUserSessionVersion( 1 );
        $this->assertSame( 1, $sv_before );

        $this->manager->revokeAllSessions( 1 );

        $sv_after = $this->manager->getUserSessionVersion( 1 );
        $this->assertSame( 2, $sv_after );
    }

    public function test_revoke_all_sessions_purges_refresh_tokens(): void {
        global $wpdb;

        // Issue two tokens for the user.
        $this->manager->issueRefreshToken( 1 );
        $this->manager->issueRefreshToken( 1 );

        $count_before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = 1"
        );
        $this->assertSame( 2, $count_before );

        $this->manager->revokeAllSessions( 1 );

        $count_after = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = 1"
        );
        $this->assertSame( 0, $count_after );
    }

    public function test_get_user_session_version_returns_null_for_soft_deleted(): void {
        global $wpdb;
        // Soft-delete the user.
        $wpdb->update(
            $wpdb->prefix . 'prode_users',
            [ 'deleted_at' => current_time( 'mysql' ) ],
            [ 'id' => 1 ]
        );

        $sv = $this->manager->getUserSessionVersion( 1 );
        $this->assertNull( $sv );

        // Restore for other tests.
        $wpdb->update(
            $wpdb->prefix . 'prode_users',
            [ 'deleted_at' => null ],
            [ 'id' => 1 ]
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inserts a minimal test user row so the manager has a real user to work with.
     */
    private function seedTestUser(): void {
        global $wpdb;

        // Only insert once.
        $exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_users WHERE id = 1"
        );
        if ( $exists > 0 ) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'              => 1,
                'tenant_id'       => 'marianista',
                'dni'             => '12345678',
                'email'           => 'test@example.com',
                'provider'        => 'google',
                'provider_id'     => 'google_sub_test',
                'display_name'    => 'Test User',
                'session_version' => 1,
                'created_at'      => current_time( 'mysql' ),
            ]
        );
    }
}
