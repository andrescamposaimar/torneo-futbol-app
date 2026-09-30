<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Auth;

use EntreRedes\Credencial\Auth\ProdeSessionGateway;
use PHPUnit\Framework\TestCase;

/**
 * Copied and adapted from entre-redes-cambios/tests/Auth/ProdeSessionGatewayTest.php.
 * ProdeSessionGateway reads `{$wpdb->prefix}prode_users`, a table owned by
 * entre-redes-prode — this test's setUp() creates a minimal fixture table
 * directly, the same way production reality relies on that plugin's own
 * InitialSchema having created it.
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

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'prode_users', [ 'session_version' => 4 ], [ 'id' => 42 ] );

        $this->assertFalse( $this->gateway->isSessionCurrent( 42, 3 ) );
        $this->assertTrue( $this->gateway->isSessionCurrent( 42, 4 ), 'The new session_version must be accepted.' );
    }

    public function test_nonexistent_prode_user_is_not_current(): void {
        $this->assertFalse( $this->gateway->isSessionCurrent( 999999, 1 ) );
    }

    public function test_missing_prode_users_table_is_not_current(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );

        $this->assertFalse( $this->gateway->isSessionCurrent( 42, 3 ) );
    }
}
