<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\RegistryPage;
use EntreRedes\Prode\Admin\RegistryRepository;
use EntreRedes\Prode\Audit\AuditLogger;
use EntreRedes\Prode\Audit\DniHasher;
use EntreRedes\Prode\Auth\SessionManager;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegistryPage::finalizeUnlink() — closes the admin-unlink
 * session revocation gap: an admin unlinking a player must revoke that
 * user's sessions, not just soft-delete the association.
 *
 * handleUnlink() always ends in `exit;` (PRG redirect pattern). A real PHP
 * `exit` would terminate the PHPUnit process before any assertion could run,
 * so finalizeUnlink() was extracted to hold every post-soft-delete side
 * effect (session revocation + audit log) without the exit-based redirect.
 * It is invoked here via ReflectionMethod::invoke() — the same private-method
 * testing convention already used in this suite (see PredictionsPageTest,
 * FechaRepositoryTest, InitialSchemaTest): setAccessible() is a no-op since
 * PHP 8.1 and deprecated since 8.5, so it is omitted.
 *
 * Runs against the in-memory SQLite shim (tests/wp-shim.php), same pattern
 * as AccountDeletionTest / RegistryRepositoryTest.
 */
class RegistryPageTest extends TestCase {

    private const USER_ID     = 77;
    private const ACTOR_WP_ID = 1;
    private const PLAYER_NAME = '999';
    private const PROVIDER    = 'google';
    private const DNI         = '11223344';

    private RegistryPage   $page;
    private SessionManager $sessionManager;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_refresh_tokens" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_audit_log" );

        update_option( 'prode_audit_dni_pepper', 'test_pepper_value' );

        $this->seedUser();

        $this->sessionManager = new SessionManager();

        $this->page = new RegistryPage(
            new RegistryRepository( $wpdb ),
            new AuditLogger(),
            new DniHasher(),
            $this->sessionManager
        );
    }

    protected function tearDown(): void {
        global $wpdb;
        // The revocation-failure test drops prode_users — recreate the schema
        // unconditionally so later tests in the run start from a clean slate.
        InitialSchema::up();
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_refresh_tokens" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_audit_log" );
    }

    // -------------------------------------------------------------------------
    // 1. The gap this change closes: admin unlink revokes all sessions.
    // -------------------------------------------------------------------------

    public function test_finalize_unlink_revokes_all_sessions(): void {
        global $wpdb;

        $sv_before = (int) $wpdb->get_var(
            "SELECT session_version FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );
        $this->assertSame( 1, $sv_before );

        $this->sessionManager->issueRefreshToken( self::USER_ID );
        $this->sessionManager->issueRefreshToken( self::USER_ID );

        $count_before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = " . self::USER_ID
        );
        $this->assertSame( 2, $count_before );

        $key = $this->invokeFinalizeUnlink();

        $this->assertSame( 'unlinked', $key );

        $sv_after = (int) $wpdb->get_var(
            "SELECT session_version FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );
        $this->assertGreaterThan(
            $sv_before,
            $sv_after,
            'Admin unlink must bump session_version, invalidating the unlinked user\'s active JWTs.'
        );

        $count_after = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_refresh_tokens WHERE user_id = " . self::USER_ID
        );
        $this->assertSame(
            0,
            $count_after,
            'Admin unlink must purge all refresh tokens for the unlinked user.'
        );
    }

    // -------------------------------------------------------------------------
    // 2. Ordering: an audit-log failure must NOT suppress revocation.
    // -------------------------------------------------------------------------

    public function test_revocation_still_happens_when_audit_log_fails(): void {
        global $wpdb;

        // Force the audit log write to fail: DniHasher::hash() throws when the
        // pepper has not been provisioned. This is a real failure mode (not a
        // fake), matching how DniHasherTest / AuditLoggerTest exercise it.
        delete_option( 'prode_audit_dni_pepper' );

        $sv_before = (int) $wpdb->get_var(
            "SELECT session_version FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );

        $key = $this->invokeFinalizeUnlink();

        $this->assertSame(
            'unlinked_no_audit',
            $key,
            'An audit-log failure must produce the unlinked_no_audit notice.'
        );

        $sv_after = (int) $wpdb->get_var(
            "SELECT session_version FROM {$wpdb->prefix}prode_users WHERE id = " . self::USER_ID
        );
        $this->assertGreaterThan(
            $sv_before,
            $sv_after,
            'A failed audit log write must NOT suppress session revocation — an unwritten audit row is a '
            . 'record-keeping gap, but a skipped revocation would reopen the exact security hole this change closes.'
        );
    }

    // -------------------------------------------------------------------------
    // 3. Revocation failure must be surfaced, not swallowed.
    // -------------------------------------------------------------------------

    public function test_revocation_failure_is_surfaced_with_its_own_notice(): void {
        global $wpdb;

        // Force SessionManager::revokeAllSessions() to fail: drop the table its
        // UPDATE targets, so wpdb->query() hits a driver-level error and
        // returns false (not "0 rows affected", which is a legitimate outcome).
        $wpdb->query( "DROP TABLE {$wpdb->prefix}prode_users" );

        $key = $this->invokeFinalizeUnlink();

        $this->assertSame(
            'unlinked_session_revoke_failed',
            $key,
            'A failed session revocation must surface its own notice — silently downgrading it to '
            . 'unlinked/unlinked_no_audit would leave the admin believing access was cut when it was not.'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function invokeFinalizeUnlink(): string {
        $ref = new \ReflectionMethod( RegistryPage::class, 'finalizeUnlink' );
        return $ref->invoke(
            $this->page,
            self::USER_ID,
            self::ACTOR_WP_ID,
            self::PLAYER_NAME,
            self::PROVIDER,
            self::DNI
        );
    }

    private function seedUser(): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'              => self::USER_ID,
                'tenant_id'       => 'marianista',
                'dni'             => self::DNI,
                'email'           => 'unlink-me@example.com',
                'provider'        => self::PROVIDER,
                'provider_id'     => 'google_sub_unlink_test',
                'display_name'    => 'Unlink Me',
                'session_version' => 1,
                'created_at'      => current_time( 'mysql' ),
            ]
        );
    }
}
