<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\RepairDisplayNamesService;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RepairDisplayNamesService.
 *
 * Runs against the in-memory SQLite shim (same setUp/tearDown as the other
 * Admin tests). The player-name lookup is injected, so no wp_posts table is
 * needed — the closure is driven from an in-test map.
 */
class RepairDisplayNamesServiceTest extends TestCase {

    /** @var array<int, string> player_id => roster full name */
    private array $roster;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_associations" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $this->roster = [];
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

    private function makeService(): RepairDisplayNamesService {
        global $wpdb;
        $roster = &$this->roster;
        return new RepairDisplayNamesService(
            $wpdb,
            static fn( int $playerId ): ?string => $roster[ $playerId ] ?? null
        );
    }

    private function insertUser(
        string $email,
        string $displayName,
        string $provider = 'apple',
        ?string $deletedAt = null
    ): int {
        global $wpdb;
        $data = [
            'tenant_id'       => 'test_tenant',
            'dni'             => '12345678',
            'email'           => $email,
            'provider'        => $provider,
            'provider_id'     => uniqid( 'pid_', true ),
            'display_name'    => $displayName,
            'session_version' => 1,
            'created_at'      => current_time( 'mysql' ),
        ];
        if ( null !== $deletedAt ) {
            $data['deleted_at'] = $deletedAt;
            $data['deleted_by'] = 'user';
        }
        $wpdb->insert( $wpdb->prefix . 'prode_users', $data );
        return (int) $wpdb->insert_id;
    }

    private function insertAssociation(
        int $userId,
        int $playerId,
        ?string $deletedAt = null
    ): void {
        global $wpdb;
        $data = [
            'user_id'     => $userId,
            'provider'    => 'apple',
            'provider_id' => uniqid( 'apid_', true ),
            'dni'         => '12345678',
            'player_id'   => $playerId,
            'created_at'  => current_time( 'mysql' ),
        ];
        if ( null !== $deletedAt ) {
            $data['deleted_at']          = $deletedAt;
            $data['deleted_by']          = 'admin';
            $data['deleted_actor_wp_id'] = 1;
        }
        $wpdb->insert( $wpdb->prefix . 'prode_associations', $data );
    }

    private function displayNameOf( int $userId ): string {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT display_name FROM {$wpdb->prefix}prode_users WHERE id = %d",
                $userId
            )
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_repairs_apple_private_relay_email_name(): void {
        $uid = $this->insertUser( 'm8h5np8k22@privaterelay.appleid.com', 'm8h5np8k22@privaterelay.appleid.com' );
        $this->insertAssociation( $uid, 501 );
        $this->roster[501] = 'Andrés Campos';

        $result = $this->makeService()->run();

        $this->assertSame( 1, $result['scanned'] );
        $this->assertSame( 1, $result['repaired'] );
        $this->assertSame( 'Andrés Campos', $this->displayNameOf( $uid ) );
    }

    public function test_repairs_name_equal_to_email_and_empty_name(): void {
        $u1 = $this->insertUser( 'juan@gmail.com', 'juan@gmail.com', 'google' );
        $this->insertAssociation( $u1, 601 );
        $this->roster[601] = 'Juan Pérez';

        $u2 = $this->insertUser( 'caro@icloud.com', '' );
        $this->insertAssociation( $u2, 602 );
        $this->roster[602] = 'Caro Díaz';

        $result = $this->makeService()->run();

        $this->assertSame( 2, $result['scanned'] );
        $this->assertSame( 2, $result['repaired'] );
        $this->assertSame( 'Juan Pérez', $this->displayNameOf( $u1 ) );
        $this->assertSame( 'Caro Díaz', $this->displayNameOf( $u2 ) );
    }

    public function test_leaves_users_with_a_real_name_untouched(): void {
        $uid = $this->insertUser( 'real@example.com', 'María González' );
        $this->insertAssociation( $uid, 701 );
        $this->roster[701] = 'Otro Nombre';

        $result = $this->makeService()->run();

        $this->assertSame( 0, $result['scanned'] );
        $this->assertSame( 0, $result['repaired'] );
        $this->assertSame( 'María González', $this->displayNameOf( $uid ) );
    }

    public function test_never_overwrites_with_empty_roster_name(): void {
        $uid = $this->insertUser( 'x@privaterelay.appleid.com', 'x@privaterelay.appleid.com' );
        $this->insertAssociation( $uid, 801 );
        // Player not in roster (e.g. unpublished) → lookup returns null.

        $result = $this->makeService()->run();

        $this->assertSame( 1, $result['scanned'] );
        $this->assertSame( 0, $result['repaired'] );
        $this->assertSame( 'x@privaterelay.appleid.com', $this->displayNameOf( $uid ) );
    }

    public function test_skips_soft_deleted_users_and_associations(): void {
        // Soft-deleted user — must not be repaired.
        $uDeleted = $this->insertUser(
            'del@privaterelay.appleid.com',
            'del@privaterelay.appleid.com',
            'apple',
            '2026-01-01 00:00:00'
        );
        $this->insertAssociation( $uDeleted, 901 );
        $this->roster[901] = 'No Tocar';

        // Active user whose ONLY association is soft-deleted — no active link to a
        // player_id, so it must not be repaired.
        $uOrphan = $this->insertUser( 'orph@privaterelay.appleid.com', 'orph@privaterelay.appleid.com' );
        $this->insertAssociation( $uOrphan, 902, '2026-01-01 00:00:00' );
        $this->roster[902] = 'Tampoco';

        $result = $this->makeService()->run();

        $this->assertSame( 0, $result['scanned'] );
        $this->assertSame( 0, $result['repaired'] );
        $this->assertSame( 'del@privaterelay.appleid.com', $this->displayNameOf( $uDeleted ) );
        $this->assertSame( 'orph@privaterelay.appleid.com', $this->displayNameOf( $uOrphan ) );
    }

    public function test_empty_dataset_returns_zero(): void {
        $result = $this->makeService()->run();
        $this->assertSame( [ 'scanned' => 0, 'repaired' => 0 ], $result );
    }
}
