<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Auth;

use EntreRedes\Cambios\Auth\ProdeSessionGateway;
use PHPUnit\Framework\TestCase;

/**
 * ProdeSessionGateway reads `{$wpdb->prefix}prode_users`, a table that
 * belongs to the entre-redes-prode plugin, not this one — InitialSchema
 * never creates it (see that class's docblock: this plugin only owns its
 * own `cambios_` tables). So this test's setUp() creates a minimal
 * `prode_users` fixture table directly, the same way production reality
 * relies on entre-redes-prode's own InitialSchema having created it —
 * exercising exactly the schema coupling ProdeSessionGateway's docblock
 * describes, without pulling in a single class from that plugin.
 */
class ProdeSessionGatewayTest extends TestCase {

    private ProdeSessionGateway $gateway;

    protected function setUp(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( "DROP TABLE IF EXISTS {$p}prode_users" );
        $wpdb->query(
            "CREATE TABLE {$p}prode_users (
                id INTEGER PRIMARY KEY,
                session_version INTEGER NOT NULL
            )"
        );

        $this->gateway = new ProdeSessionGateway( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );
    }

    private function insertProdeUser( int $id, int $sessionVersion ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'prode_users', [ 'id' => $id, 'session_version' => $sessionVersion ] );
    }

    public function test_matching_session_version_is_current(): void {
        $this->insertProdeUser( 42, 3 );

        $this->assertTrue( $this->gateway->isSessionCurrent( 42, 3 ) );
    }

    public function test_stale_session_version_after_a_simulated_revoke_is_not_current(): void {
        $this->insertProdeUser( 42, 3 );

        // Simulates SessionManager::revokeAllSessions() incrementing the
        // counter — the token still carries the OLD value (3).
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'prode_users', [ 'session_version' => 4 ], [ 'id' => 42 ] );

        $this->assertFalse( $this->gateway->isSessionCurrent( 42, 3 ) );
        $this->assertTrue( $this->gateway->isSessionCurrent( 42, 4 ), 'The new session_version must be accepted.' );
    }

    public function test_nonexistent_prode_user_is_not_current(): void {
        $this->assertFalse( $this->gateway->isSessionCurrent( 999999, 1 ) );
    }

    /**
     * The class docblock promises this reads as "session not current", not
     * as a fatal error, if entre-redes-prode is deactivated (or its table
     * renamed) out from under this plugin. Verified by hand once already —
     * this test is so nobody breaks that behavior again without noticing.
     */
    public function test_missing_prode_users_table_is_not_current(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );

        $this->assertFalse( $this->gateway->isSessionCurrent( 42, 3 ) );
    }
}
