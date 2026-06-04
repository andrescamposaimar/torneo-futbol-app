<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\AuditLogRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditLogRepository (D1, TEST-03, BIT-01..08).
 *
 * Runs against the in-memory SQLite shim.
 * Read-only repository — no write methods to test.
 */
class AuditLogRepositoryTest extends TestCase {

    private AuditLogRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_audit_log" );

        $this->repo = new AuditLogRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_audit_log" );
        InitialSchema::up();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inserts a prode_audit_log row.
     */
    private function insertEvent(
        string $eventType = 'association_created',
        string $createdAt = '2026-01-15 10:00:00',
        string $tenantId = 'test_tenant'
    ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert(
            $p . 'prode_audit_log',
            [
                'event_type' => $eventType,
                'tenant_id'  => $tenantId,
                'created_at' => $createdAt,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // listEvents — BIT-01, no filters
    // -------------------------------------------------------------------------

    public function test_listEvents_returns_all_when_no_filters(): void {
        $this->insertEvent( 'association_created', '2026-01-15 10:00:00' );
        $this->insertEvent( 'admin_unlink', '2026-01-16 10:00:00' );

        $rows = $this->repo->listEvents( null, null, null, 25, 0 );

        $this->assertCount( 2, $rows );
    }

    // -------------------------------------------------------------------------
    // listEvents — BIT-05, event_type filter
    // -------------------------------------------------------------------------

    public function test_listEvents_filters_by_event_type(): void {
        $this->insertEvent( 'association_created', '2026-01-15 10:00:00' );
        $this->insertEvent( 'admin_unlink', '2026-01-16 10:00:00' );
        $this->insertEvent( 'admin_unlink', '2026-01-17 10:00:00' );

        $rows = $this->repo->listEvents( 'admin_unlink', null, null, 25, 0 );

        $this->assertCount( 2, $rows );
        foreach ( $rows as $row ) {
            $this->assertSame( 'admin_unlink', $row['event_type'] );
        }
    }

    // -------------------------------------------------------------------------
    // listEvents — BIT-06, date_from only
    // -------------------------------------------------------------------------

    public function test_listEvents_filters_by_date_from_only(): void {
        $this->insertEvent( 'association_created', '2026-01-10 00:00:00' );
        $this->insertEvent( 'association_created', '2026-01-20 00:00:00' );
        $this->insertEvent( 'association_created', '2026-01-25 00:00:00' );

        // from = 2026-01-15 → should return the Jan 20 and Jan 25 events.
        $rows = $this->repo->listEvents( null, '2026-01-15', null, 25, 0 );

        $this->assertCount( 2, $rows );
    }

    // -------------------------------------------------------------------------
    // listEvents — BIT-06, date range (from + to)
    // -------------------------------------------------------------------------

    public function test_listEvents_filters_by_date_range(): void {
        $this->insertEvent( 'association_created', '2026-01-10 00:00:00' );
        $this->insertEvent( 'association_created', '2026-01-20 00:00:00' );
        $this->insertEvent( 'association_created', '2026-01-25 00:00:00' );
        $this->insertEvent( 'association_created', '2026-02-01 00:00:00' );

        // from=2026-01-15 to=2026-01-26 → Jan 20 and Jan 25.
        $rows = $this->repo->listEvents( null, '2026-01-15', '2026-01-26', 25, 0 );

        $this->assertCount( 2, $rows );
    }

    // -------------------------------------------------------------------------
    // countEvents — matches listEvents for same filters
    // -------------------------------------------------------------------------

    public function test_countEvents_matches_listEvents_for_same_filters(): void {
        $this->insertEvent( 'association_created', '2026-01-10 00:00:00' );
        $this->insertEvent( 'admin_unlink', '2026-01-20 00:00:00' );
        $this->insertEvent( 'admin_unlink', '2026-01-25 00:00:00' );

        $count = $this->repo->countEvents( 'admin_unlink', null, null );
        $rows  = $this->repo->listEvents( 'admin_unlink', null, null, 25, 0 );

        $this->assertSame( count( $rows ), $count );
    }

    // -------------------------------------------------------------------------
    // BIT-06: invalid date string is silently ignored
    // -------------------------------------------------------------------------

    public function test_invalid_date_string_is_silently_ignored(): void {
        $this->insertEvent( 'association_created', '2026-01-15 10:00:00' );

        // Non-parseable date string → filter not applied → all 1 row returned.
        $rows = $this->repo->listEvents( null, 'not-a-date', null, 25, 0 );

        $this->assertCount( 1, $rows );
    }
}
