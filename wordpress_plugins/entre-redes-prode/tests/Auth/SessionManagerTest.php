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
        global $wpdb;

        // Ensure tables exist in the shim's in-memory SQLite DB.
        \EntreRedes\Prode\Migrations\InitialSchema::up();
        $this->seedTestUser();

        // The SQLite shim shares one in-memory DB across the whole run and has no
        // per-test rollback (unlike the WP test framework). Reset the mutable
        // state of the shared user row + clear leftover tokens/errors so tests are
        // order-independent.
        $wpdb->update(
            $wpdb->prefix . 'prode_users',
            [ 'session_version' => 1, 'deleted_at' => null ],
            [ 'id' => 1 ]
        );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_refresh_tokens" );
        $wpdb->last_error = null;

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

    /**
     * W2: reusing an already-rotated (revoked) refresh token is a theft signal —
     * it must revoke the entire session family, not just reject the one token.
     */
    public function test_reusing_revoked_refresh_token_triggers_family_revocation(): void {
        global $wpdb;

        $token = $this->manager->issueRefreshToken( 1 );
        // First rotation revokes $token and issues a fresh one.
        $this->manager->rotateRefreshToken( $token );

        $sv_before = $this->manager->getUserSessionVersion( 1 );

        // Reusing the now-revoked token still surfaces refresh_token_invalid...
        $caught = null;
        try {
            $this->manager->rotateRefreshToken( $token );
        } catch ( \InvalidArgumentException $e ) {
            $caught = $e->getMessage();
        }
        $this->assertSame( 'refresh_token_invalid', $caught );

        // ...but as a side effect the session_version is bumped (invalidating
        // every outstanding JWT)...
        $this->assertSame( $sv_before + 1, $this->manager->getUserSessionVersion( 1 ) );

        // ...and all refresh tokens for the user are purged.
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = 1"
        );
        $this->assertSame( 0, $count );
    }

    /**
     * W1: when two concurrent registrations race past the application-level
     * conflict check, the DB unique index must surface as a friendly conflict
     * (dni_already_associated), not a 500. The production guarantee is the
     * MySQL-only uq_tenant_active_dni index; here we simulate it with a plain
     * unique index since ensureActiveDniIndex() is a no-op on the SQLite shim.
     */
    public function test_create_user_with_duplicate_active_dni_returns_conflict(): void {
        global $wpdb;

        $wpdb->query(
            "CREATE UNIQUE INDEX IF NOT EXISTS test_uq_tenant_dni
                ON {$wpdb->prefix}prode_users (tenant_id, dni)"
        );

        try {
            // First registration for this DNI succeeds.
            $first = $this->manager->createUserWithAssociation(
                'google', 'sub-A', '99999999', 'a@example.com', 'User A', 100
            );
            $this->assertGreaterThan( 0, $first['user_id'] );

            // A concurrent second registration for the SAME DNI hits the unique
            // index and must be reported as a conflict, not a server error.
            $caught = null;
            try {
                $this->manager->createUserWithAssociation(
                    'apple', 'sub-B', '99999999', 'b@example.com', 'User B', 101
                );
            } catch ( \InvalidArgumentException $e ) {
                $caught = $e->getMessage();
            }
            $this->assertSame( 'dni_already_associated', $caught );
        } finally {
            $wpdb->query( "DROP INDEX IF EXISTS test_uq_tenant_dni" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users WHERE dni = '99999999'" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_associations WHERE dni = '99999999'" );
            // The intentional duplicate insert set last_error; clear it so it does
            // not leak into later tests that assert on $wpdb->last_error.
            $wpdb->last_error = null;
        }
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
