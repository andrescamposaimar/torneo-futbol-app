<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\SettingsPage;
use EntreRedes\Prode\Cron\BackfillMatchMetaCron;
use EntreRedes\Prode\Fecha\BackfillMatchMetaService;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the "Backfill match meta" admin button on the SettingsPage
 * (the manual on-demand trigger for BackfillMatchMetaService).
 *
 * Mirrors RepairDisplayNamesServiceTest strategy: tests the service layer
 * directly (BackfillMatchMetaService) and the public dispatcher seam on
 * BackfillMatchMetaCron, plus a SettingsPage construction smoke test to
 * verify the new dependency is wired.
 *
 * The SettingsPage POST handler itself uses wp_safe_redirect + exit (PRG
 * pattern, same as handleRepairNames). Those redirects are not tested here
 * because they require a live request context; the service and wiring are
 * covered instead.
 */
class BackfillMatchMetaAdminHandlerTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function partido( int $id ): array {
        return [
            'id'               => $id,
            'equipo_local'     => "Home {$id}",
            'equipo_visitante' => "Away {$id}",
            'liga'             => "Zona {$id}",
            'escudo_local'     => "https://example.com/home-{$id}.png",
            'escudo_visitante' => "https://example.com/away-{$id}.png",
        ];
    }

    private function insertLegacyMatch( int $fechaId, int $matchId, string $kickoff ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'prode_fecha_matches', [
            'fecha_id'      => $fechaId,
            'match_id'      => $matchId,
            'match_kickoff' => $kickoff,
        ] );
    }

    // -------------------------------------------------------------------------
    // BackfillMatchMetaService — admin use-case scenarios
    // -------------------------------------------------------------------------

    /**
     * Core scenario: the admin button calls service->run() and gets the count.
     * Mirrors the "handler runs the service and reports the count" requirement.
     */
    public function test_service_run_returns_count_of_backfilled_rows(): void {
        $this->insertLegacyMatch( 1, 22608, '2026-06-06 13:45:00' );
        $this->insertLegacyMatch( 1, 22609, '2026-06-06 15:00:00' );

        global $wpdb;
        $service = new BackfillMatchMetaService(
            $wpdb,
            fn( string $date ): array => '2026-06-06' === $date
                ? [ $this->partido( 22608 ), $this->partido( 22609 ) ]
                : []
        );

        $updated = $service->run();

        $this->assertSame( 2, $updated );
    }

    /**
     * No-op case: when all rows already have snapshots, run() returns 0.
     * Mirrors the "no-op case (0 rows)" requirement.
     */
    public function test_service_run_returns_zero_when_nothing_to_backfill(): void {
        // No rows inserted — empty table.
        global $wpdb;
        $service = new BackfillMatchMetaService(
            $wpdb,
            fn( string $date ): array => []
        );

        $updated = $service->run();

        $this->assertSame( 0, $updated );
    }

    /**
     * Idempotency: a second run on an already-filled table is a no-op (0).
     */
    public function test_service_run_is_idempotent_no_op_after_first_fill(): void {
        $this->insertLegacyMatch( 1, 22608, '2026-06-06 13:45:00' );

        global $wpdb;
        $service = new BackfillMatchMetaService(
            $wpdb,
            fn( string $date ): array => [ $this->partido( 22608 ) ]
        );

        $first  = $service->run();
        $second = $service->run();

        $this->assertSame( 1, $first );
        $this->assertSame( 0, $second );
    }

    // -------------------------------------------------------------------------
    // BackfillMatchMetaCron::defaultDispatcher() — public accessibility
    // -------------------------------------------------------------------------

    /**
     * The production dispatcher must be public so Plugin.php can pass it to
     * BackfillMatchMetaService when the admin button is clicked.
     */
    public function test_default_dispatcher_is_publicly_accessible(): void {
        $dispatcher = BackfillMatchMetaCron::defaultDispatcher();

        $this->assertIsCallable( $dispatcher );
    }

    // -------------------------------------------------------------------------
    // SettingsPage — constructor accepts BackfillMatchMetaService
    // -------------------------------------------------------------------------

    /**
     * SettingsPage must accept a BackfillMatchMetaService as its fourth
     * constructor argument without throwing.
     * Smoke-tests the wiring added in Plugin.php.
     */
    public function test_settings_page_accepts_backfill_service(): void {
        global $wpdb;

        $settingsRepo = new \EntreRedes\Prode\Admin\SettingsRepository( $wpdb );
        $backfillSvc  = new BackfillMatchMetaService(
            $wpdb,
            fn( string $d ): array => []
        );

        // SeedFechaService and RepairDisplayNamesService need full wiring;
        // we use test doubles instead.
        $seedMock   = $this->createMock( \EntreRedes\Prode\Fecha\SeedFechaService::class );
        $repairMock = $this->createMock( \EntreRedes\Prode\Admin\RepairDisplayNamesService::class );

        // Constructor must complete without throwing.
        $page = new SettingsPage( $settingsRepo, $seedMock, $repairMock, $backfillSvc );

        $this->assertInstanceOf( SettingsPage::class, $page );
    }

    // -------------------------------------------------------------------------
    // SettingsPage — capability guard on backfill action
    // -------------------------------------------------------------------------

    /**
     * handlePost() with prode_action = 'backfill_match_meta' must call wp_die
     * when the user does not have manage_options capability.
     * current_user_can() returns false in the shim.
     */
    public function test_backfill_handler_dies_when_user_lacks_capability(): void {
        global $wpdb;

        $settingsRepo = new \EntreRedes\Prode\Admin\SettingsRepository( $wpdb );
        $backfillSvc  = new BackfillMatchMetaService(
            $wpdb,
            fn( string $d ): array => []
        );
        $seedMock   = $this->createMock( \EntreRedes\Prode\Fecha\SeedFechaService::class );
        $repairMock = $this->createMock( \EntreRedes\Prode\Admin\RepairDisplayNamesService::class );

        $page = new SettingsPage( $settingsRepo, $seedMock, $repairMock, $backfillSvc );

        $_POST['prode_action'] = 'backfill_match_meta';

        // wp_die() in the shim throws \RuntimeException.
        $this->expectException( \RuntimeException::class );
        $page->handlePost();
    }
}
