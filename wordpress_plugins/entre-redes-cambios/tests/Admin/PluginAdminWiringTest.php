<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Admin;

use EntreRedes\Cambios\Admin\BandejaPage;
use EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * THE ADMIN WIRING TEST — same spirit as tests/PluginTest.php (the REST
 * wiring suite), point 6 of this slice's task brief: BandejaPage /
 * ProcessOwnerAuthorizer / AdminMenu each have their own coverage elsewhere
 * (BandejaPageTest, ProcessOwnerAuthorizerTest); what none of those suites
 * can see is Plugin::boot() itself forgetting to register the menu under
 * `is_admin()`, or the REAL, production-wired `admin_init` handler somehow
 * skipping authorization — exactly the "a correct, tested piece that nothing
 * production ever actually called" shape of bug this plugin has hit before
 * (see tests/PluginTest.php's own docblock).
 */
class PluginAdminWiringTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_capitan' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $this->resetPluginBootState();
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_capitan' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $this->resetPluginBootState();
        $_POST = [];
    }

    public function test_boot_registers_the_bandeja_menu_when_is_admin(): void {
        $GLOBALS['wp_test_is_admin'] = true;

        Plugin::boot();
        do_action( 'admin_menu' );

        $menus = $GLOBALS['_prode_test_registered_admin_menus'] ?? [];

        $found = array_values( array_filter(
            $menus,
            static fn ( array $m ) => ( $m['menu_slug'] ?? null ) === BandejaPage::SLUG
        ) );

        $this->assertNotEmpty( $found, 'Expected the bandeja menu (slug: ' . BandejaPage::SLUG . ') to be registered.' );
        $this->assertSame( ProcessOwnerAuthorizer::CAPABILITY, $found[0]['capability'], 'The menu must be gated by gestionar_cambios, not manage_options.' );
        $this->assertIsCallable( $found[0]['function'] );
    }

    public function test_boot_does_not_register_the_bandeja_menu_outside_wp_admin(): void {
        $GLOBALS['wp_test_is_admin'] = false;

        Plugin::boot();
        do_action( 'admin_menu' );

        $menus = $GLOBALS['_prode_test_registered_admin_menus'] ?? [];
        $found = array_filter( $menus, static fn ( array $m ) => ( $m['menu_slug'] ?? null ) === BandejaPage::SLUG );

        $this->assertEmpty( $found, 'A non-admin request must never register the process-owner menu.' );
    }

    /**
     * Retrieves the REAL callback boot() wired to `admin_init` for
     * BandejaPage::handlePost() — bound to the ACTUAL production
     * ProcessOwnerAuthorizer / SolicitudRepository instances, none of them
     * test doubles — and invokes it directly with a request that grants no
     * capability at all.
     *
     * `cambios_solicitud` is asserted unchanged BEFORE and AFTER the call —
     * same "prove the REAL wiring, not a mock" discipline as
     * tests/PluginTest.php's own equivalent REST test.
     */
    public function test_boot_wired_admin_init_handler_rejects_an_unauthorized_request_without_touching_the_repository(): void {
        $GLOBALS['wp_test_is_admin'] = true;

        Plugin::boot();
        do_action( 'admin_menu' );

        global $wpdb;
        $countBefore = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_solicitud" );
        $this->assertSame( 0, $countBefore );

        $GLOBALS['wp_test_current_user_can'] = false;
        $_POST = [ 'cambios_action' => 'aprobar', 'solicitud_id' => '1', 'nota' => '' ];

        $callback = $this->findRegisteredAdminInitHandlePostCallback();

        try {
            $callback();
            $this->fail( 'Expected the wired admin_init handler to reject an unauthorized request.' );
        } catch ( \RuntimeException $e ) {
            // Expected — wp_die() throws in this shim.
        }

        $countAfter = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_solicitud" );
        $this->assertSame( 0, $countAfter, 'BandejaPage must never touch the repository when authorization fails.' );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function findRegisteredAdminInitHandlePostCallback(): callable {
        foreach ( $GLOBALS['_prode_test_action_registrations']['admin_init'] ?? [] as $registered ) {
            $callback = $registered['callback'];

            if ( is_array( $callback ) && ( $callback[1] ?? null ) === 'handlePost' ) {
                return $callback;
            }
        }

        $this->fail( 'No admin_init callback bound to handlePost() was found — was admin_menu fired first?' );
    }

    /**
     * Same rationale as tests/PluginTest.php's own resetPluginBootState():
     * Plugin::boot() is idempotent by design, and this suite's own boot()
     * call must be deterministic regardless of what other test files ran
     * before it in the same PHPUnit process.
     */
    private function resetPluginBootState(): void {
        $property = new \ReflectionProperty( Plugin::class, 'booted' );
        $property->setValue( null, false );

        unset(
            $GLOBALS['_prode_test_registered_routes'],
            $GLOBALS['_prode_test_registered_admin_menus'],
            $GLOBALS['_prode_test_action_callbacks']['rest_api_init'],
            $GLOBALS['_prode_test_action_registrations']['rest_api_init'],
            $GLOBALS['_prode_test_action_callbacks']['admin_menu'],
            $GLOBALS['_prode_test_action_registrations']['admin_menu'],
            $GLOBALS['_prode_test_action_callbacks']['admin_init'],
            $GLOBALS['_prode_test_action_registrations']['admin_init'],
            $GLOBALS['wp_test_is_admin'],
            $GLOBALS['wp_test_current_user_can']
        );
    }
}
