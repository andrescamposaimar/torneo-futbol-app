<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\RegistryRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegistryRepository (D1, TEST-02, REG-01..07, EDGE-03, CC-06).
 *
 * Runs against the in-memory SQLite shim. setUp/tearDown mirror FechaRepositoryTest.
 *
 * NO JOIN to wp_users at any point (CC-06, AMENDMENT-001).
 */
class RegistryRepositoryTest extends TestCase {

    private RegistryRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        // Clear tables in FK order.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_associations" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $this->repo = new RegistryRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_associations" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
        InitialSchema::up();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inserts a prode_users row; returns inserted id.
     */
    private function insertUser(
        string $tenantId = 'test_tenant',
        string $email = 'user@example.com',
        string $displayName = 'Test User',
        ?string $deletedAt = null
    ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $data = [
            'tenant_id'       => $tenantId,
            'dni'             => '12345678',
            'email'           => $email,
            'provider'        => 'google',
            'provider_id'     => uniqid( 'pid_', true ),
            'display_name'    => $displayName,
            'session_version' => 1,
            'created_at'      => current_time( 'mysql' ),
        ];

        if ( null !== $deletedAt ) {
            $data['deleted_at'] = $deletedAt;
            $data['deleted_by'] = 'user';
        }

        $wpdb->insert( $p . 'prode_users', $data );
        return (int) $wpdb->insert_id;
    }

    /**
     * Inserts a prode_associations row for the given user_id; returns inserted id.
     */
    private function insertAssociation(
        int $userId,
        string $playerName = 'Player One',
        ?string $deletedAt = null,
        ?int $deletedActorWpId = null
    ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $data = [
            'user_id'    => $userId,
            'provider'   => 'google',
            'provider_id' => uniqid( 'apid_', true ),
            'dni'        => '12345678',
            'player_id'  => 999,
            'created_at' => current_time( 'mysql' ),
        ];

        if ( null !== $deletedAt ) {
            $data['deleted_at']           = $deletedAt;
            $data['deleted_by']           = 'admin';
            $data['deleted_actor_wp_id']  = $deletedActorWpId ?? 1;
        }

        $wpdb->insert( $p . 'prode_associations', $data );
        return (int) $wpdb->insert_id;
    }

    // -------------------------------------------------------------------------
    // listUsers — REG-01, REG-03, REG-04
    // -------------------------------------------------------------------------

    public function test_listUsers_returns_active_users_paginated(): void {
        $uid1 = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        $uid2 = $this->insertUser( 'test_tenant', 'b@example.com', 'Bob' );
        $this->insertAssociation( $uid1, 'Player Alice' );
        $this->insertAssociation( $uid2, 'Player Bob' );

        $rows = $this->repo->listUsers( 'test_tenant', true, 25, 0 );

        $this->assertCount( 2, $rows );
    }

    public function test_listUsers_returns_deleted_users_when_activeOnly_false(): void {
        // Alice is deleted; Bob is active.
        $uid1 = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice', '2026-01-01 00:00:00' );
        $uid2 = $this->insertUser( 'test_tenant', 'b@example.com', 'Bob' );
        $this->insertAssociation( $uid1 );
        $this->insertAssociation( $uid2 );

        // Active only → 1 user (Bob).
        $active = $this->repo->listUsers( 'test_tenant', true, 25, 0 );
        $this->assertCount( 1, $active );

        // Deleted only (activeOnly=false → "Eliminados" tab) → 1 user (Alice).
        $deleted = $this->repo->listUsers( 'test_tenant', false, 25, 0 );
        $this->assertCount( 1, $deleted );
    }

    // -------------------------------------------------------------------------
    // countUsers — REG-04
    // -------------------------------------------------------------------------

    public function test_countUsers_matches_listUsers_result_count(): void {
        $uid1 = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        $uid2 = $this->insertUser( 'test_tenant', 'b@example.com', 'Bob' );
        $this->insertAssociation( $uid1 );
        $this->insertAssociation( $uid2 );

        $count = $this->repo->countUsers( 'test_tenant', true );
        $rows  = $this->repo->listUsers( 'test_tenant', true, 25, 0 );

        $this->assertSame( count( $rows ), $count );
    }

    // -------------------------------------------------------------------------
    // findUserForUnlink — EDGE-03
    // -------------------------------------------------------------------------

    public function test_findUserForUnlink_returns_null_when_no_active_association(): void {
        $uid = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        // Insert a soft-deleted association (no active one).
        $this->insertAssociation( $uid, 'Player Alice', '2026-01-01 00:00:00', 1 );

        $result = $this->repo->findUserForUnlink( 'test_tenant', $uid );

        $this->assertNull( $result );
    }

    public function test_findUserForUnlink_returns_association_data_when_active(): void {
        $uid = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        $this->insertAssociation( $uid, 'Player Alice' );

        $result = $this->repo->findUserForUnlink( 'test_tenant', $uid );

        $this->assertNotNull( $result );
        $this->assertArrayHasKey( 'user_id', $result );
        $this->assertSame( $uid, (int) $result['user_id'] );
    }

    // -------------------------------------------------------------------------
    // unlinkAssociation — REG-06, EDGE-03 idempotency
    // -------------------------------------------------------------------------

    public function test_unlinkAssociation_soft_deletes_sets_deleted_at_deleted_by_actor_wp_id(): void {
        global $wpdb;
        $p   = $wpdb->prefix;

        $uid = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        $this->insertAssociation( $uid );

        $result = $this->repo->unlinkAssociation( $uid, 42 );

        $this->assertTrue( $result );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT deleted_at, deleted_by, deleted_actor_wp_id
                   FROM {$p}prode_associations
                  WHERE user_id = %d",
                $uid
            ),
            ARRAY_A
        );

        $this->assertNotNull( $row['deleted_at'], 'deleted_at must be set after unlink' );
        $this->assertSame( 'admin', $row['deleted_by'] );
        $this->assertSame( '42', (string) $row['deleted_actor_wp_id'] );
    }

    public function test_unlinkAssociation_returns_false_when_already_unlinked(): void {
        $uid = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        // Insert already-deleted association.
        $this->insertAssociation( $uid, 'Player Alice', '2026-01-01 00:00:00', 1 );

        $result = $this->repo->unlinkAssociation( $uid, 99 );

        $this->assertFalse( $result );
    }

    // -------------------------------------------------------------------------
    // listUsers assoc_id sentinel — production bugfix (0.5.0)
    // -------------------------------------------------------------------------

    /**
     * listUsers must expose assoc_id so the render layer can distinguish
     * "has active association" (non-null assoc_id) from "no active association"
     * (assoc_id IS NULL via unmatched LEFT JOIN).
     *
     * Before the fix: only assoc_deleted_at was selected; both matched and
     * unmatched LEFT JOIN rows returned NULL for that column, making the gate
     * always true (ambiguous NULL sentinel bug).
     */
    public function test_listUsers_exposes_assoc_id_non_null_when_active_association_exists(): void {
        $uid = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        $this->insertAssociation( $uid );

        $rows = $this->repo->listUsers( 'test_tenant', true, 25, 0 );

        $this->assertCount( 1, $rows );
        $this->assertArrayHasKey( 'assoc_id', $rows[0], 'assoc_id key must be present in listUsers row' );
        $this->assertNotNull( $rows[0]['assoc_id'], 'assoc_id must be non-null when active association exists' );
    }

    public function test_listUsers_exposes_assoc_id_null_after_association_unlinked(): void {
        $uid = $this->insertUser( 'test_tenant', 'a@example.com', 'Alice' );
        $this->insertAssociation( $uid );

        // Perform the unlink.
        $this->repo->unlinkAssociation( $uid, 1 );

        // After unlink the LEFT JOIN finds no active row → assoc_id must be NULL.
        $rows = $this->repo->listUsers( 'test_tenant', true, 25, 0 );

        $this->assertCount( 1, $rows );
        $this->assertArrayHasKey( 'assoc_id', $rows[0], 'assoc_id key must be present even after unlink' );
        $this->assertNull( $rows[0]['assoc_id'], 'assoc_id must be null after unlinkAssociation' );
        // dni and player_id must also be null (LEFT JOIN returns NULLs for all assoc columns).
        $this->assertNull( $rows[0]['dni'], 'dni must be null after unlink' );
        $this->assertNull( $rows[0]['player_id'], 'player_id must be null after unlink' );
    }
}
