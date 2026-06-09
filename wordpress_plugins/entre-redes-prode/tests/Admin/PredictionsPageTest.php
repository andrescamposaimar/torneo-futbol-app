<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\PredictionsPage;
use EntreRedes\Prode\Admin\RegistryRepository;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PredictionsPage.
 *
 * T-15 (Strict TDD — RED written first).
 *
 * The shim's current_user_can() always returns false, so render() will always
 * call wp_die(), which in the shim throws RuntimeException. This lets us verify
 * the capability guard without a WP install.
 *
 * Tests that require actual rendering (HTML output) are OUT OF SCOPE here;
 * admin rendering is tested manually.
 */
class PredictionsPageTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
    }

    private function makePage(): PredictionsPage {
        global $wpdb;
        return new PredictionsPage(
            new PredictionRepository( $wpdb ),
            new RegistryRepository( $wpdb )
        );
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_page_can_be_instantiated(): void {
        $page = $this->makePage();
        $this->assertInstanceOf( PredictionsPage::class, $page );
    }

    // -------------------------------------------------------------------------
    // Capability guard
    // -------------------------------------------------------------------------

    public function test_render_throws_when_user_cannot_manage_options(): void {
        // The shim's current_user_can() always returns false.
        // The shim's wp_die() throws RuntimeException.
        $this->expectException( \RuntimeException::class );

        $page = $this->makePage();
        $page->render();
    }
}
