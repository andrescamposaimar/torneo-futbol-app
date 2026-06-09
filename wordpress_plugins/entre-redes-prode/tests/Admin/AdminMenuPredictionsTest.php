<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\AdminMenu;
use EntreRedes\Prode\Admin\AuditLogPage;
use EntreRedes\Prode\Admin\AuditLogRepository;
use EntreRedes\Prode\Admin\PredictionsPage;
use EntreRedes\Prode\Admin\RegistryPage;
use EntreRedes\Prode\Admin\RegistryRepository;
use EntreRedes\Prode\Admin\SettingsPage;
use EntreRedes\Prode\Admin\SettingsRepository;
use EntreRedes\Prode\Audit\AuditLogger;
use EntreRedes\Prode\Audit\DniHasher;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminMenu wiring with PredictionsPage.
 *
 * T-16 (Strict TDD — RED written first).
 *
 * Verified:
 *   - AdminMenu constructor accepts PredictionsPage as 4th argument.
 *   - register() runs without error in the headless test context (all WP
 *     hook functions are no-ops in the shim).
 */
class AdminMenuPredictionsTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();
    }

    private function makeAdminMenu(): AdminMenu {
        global $wpdb;

        $settingsRepo   = new SettingsRepository( $wpdb );
        $registryRepo   = new RegistryRepository( $wpdb );
        $auditLogRepo   = new AuditLogRepository( $wpdb );
        $predRepo       = new PredictionRepository( $wpdb );

        $settings = new Settings( $wpdb );
        $lock     = new LockComputer();

        // SeedFechaService requires a resolver callable — provide a stub.
        $resolverFn = static fn() => null;
        $seedService = new \EntreRedes\Prode\Fecha\SeedFechaService(
            $settings,
            $lock,
            new \EntreRedes\Prode\Fecha\FechaRepository( $wpdb ),
            $resolverFn
        );

        $settingsPage    = new SettingsPage( $settingsRepo, $seedService, $this->makeRepairService( $wpdb ) );
        $registryPage    = new RegistryPage( $registryRepo, new AuditLogger(), new DniHasher() );
        $auditLogPage    = new AuditLogPage( $auditLogRepo );
        $predictionsPage = new PredictionsPage( $predRepo, $registryRepo );

        return new AdminMenu( $settingsPage, $registryPage, $auditLogPage, $predictionsPage );
    }

    private function makeRepairService( \wpdb $wpdb ): \EntreRedes\Prode\Admin\RepairDisplayNamesService {
        return new \EntreRedes\Prode\Admin\RepairDisplayNamesService(
            $wpdb,
            static fn( int $id ): ?string => null
        );
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_admin_menu_accepts_predictions_page_in_constructor(): void {
        $menu = $this->makeAdminMenu();
        $this->assertInstanceOf( AdminMenu::class, $menu );
    }

    // -------------------------------------------------------------------------
    // register() — runs without error in headless context
    // -------------------------------------------------------------------------

    public function test_register_runs_without_error(): void {
        $menu = $this->makeAdminMenu();
        // All WP functions (add_menu_page, add_submenu_page, add_action) are no-ops
        // in the shim, so register() must complete without throwing.
        $menu->register();
        $this->assertTrue( true ); // If we reach here, no exception was thrown.
    }
}
