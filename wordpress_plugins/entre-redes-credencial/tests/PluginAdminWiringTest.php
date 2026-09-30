<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests;

use EntreRedes\Credencial\Admin\PendingPhotosPage;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Proves Plugin::boot() actually wires "Credenciales > Fotos pendientes"
 * (design D12) into wp-admin — same "prove the CABLE, not the pieces"
 * rationale as PluginRestWiringTest. Every collaborator has its own deep unit
 * coverage elsewhere (ApprovalReviewServiceTest, PendingPhotosPageTest); this
 * only proves boot() does not forget to register the menu, gated correctly
 * behind is_admin() (never registered on a plain front-end/REST request).
 */
class PluginAdminWiringTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();
        $this->resetPluginBootState();
        $GLOBALS['wp_test_is_admin'] = false;
    }

    protected function tearDown(): void {
        $this->resetPluginBootState();
        unset( $GLOBALS['wp_test_is_admin'] );
    }

    public function test_boot_registers_the_credenciales_menu_when_in_wp_admin(): void {
        $GLOBALS['wp_test_is_admin'] = true;

        Plugin::boot();
        do_action( 'admin_menu' );

        $menus = $GLOBALS['_prode_test_registered_admin_menus'] ?? [];
        $match = array_filter(
            $menus,
            static fn( array $m ): bool => PendingPhotosPage::SLUG === ( $m['menu_slug'] ?? null )
        );

        $this->assertNotEmpty( $match, '"Credenciales" menu must be registered.' );
        $this->assertSame( 'manage_options', array_values( $match )[0]['capability'] );
    }

    public function test_boot_does_not_register_the_menu_outside_wp_admin(): void {
        $GLOBALS['wp_test_is_admin'] = false;

        Plugin::boot();
        do_action( 'admin_menu' );

        $menus = $GLOBALS['_prode_test_registered_admin_menus'] ?? [];
        $match = array_filter(
            $menus,
            static fn( array $m ): bool => PendingPhotosPage::SLUG === ( $m['menu_slug'] ?? null )
        );

        $this->assertEmpty( $match, 'the admin menu must never register on a non-admin request.' );
    }

    public function test_boot_registers_the_post_handler_on_admin_init(): void {
        $GLOBALS['wp_test_is_admin'] = true;

        Plugin::boot();
        do_action( 'admin_menu' );

        $regs = $GLOBALS['_prode_test_action_registrations']['admin_init'] ?? [];
        $match = array_filter(
            $regs,
            static fn( array $reg ): bool => is_array( $reg['callback'] )
                && $reg['callback'][0] instanceof PendingPhotosPage
                && 'handlePost' === $reg['callback'][1]
        );

        $this->assertNotEmpty( $match, 'PendingPhotosPage::handlePost must be bound to admin_init.' );
    }

    private function resetPluginBootState(): void {
        $property = new \ReflectionProperty( Plugin::class, 'booted' );
        $property->setValue( null, false );

        unset(
            $GLOBALS['_prode_test_registered_admin_menus'],
            $GLOBALS['_prode_test_action_callbacks']['admin_menu'],
            $GLOBALS['_prode_test_action_registrations']['admin_menu'],
            $GLOBALS['_prode_test_action_callbacks']['admin_init'],
            $GLOBALS['_prode_test_action_registrations']['admin_init']
        );
    }
}
