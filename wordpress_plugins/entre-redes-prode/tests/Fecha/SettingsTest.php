<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Fecha\Settings accessor.
 *
 * Uses the in-memory SQLite shim. Each test resets the prode_settings table
 * rows it touches so tests remain order-independent.
 */
class SettingsTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        // Clear the settings table so each test starts from a known state.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
    }

    protected function tearDown(): void {
        global $wpdb;
        // Restore seeds so subsequent tests (e.g. InitialSchemaTest) see the
        // expected values — the shared SQLite DB has no per-test rollback.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up(); // re-seeds defaults via INSERT IGNORE
    }

    // -------------------------------------------------------------------------
    // lock_hours_before — already-seeded key
    // -------------------------------------------------------------------------

    public function test_lock_hours_before_returns_seeded_value_as_int(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT OR IGNORE INTO {$p}prode_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                'lock_hours_before',
                '24',
                $now
            )
        );

        $settings = new Settings( $wpdb );
        $this->assertSame( 24, $settings->lockHoursBefore() );
    }

    // -------------------------------------------------------------------------
    // prode_season_id — new G0 key
    // -------------------------------------------------------------------------

    public function test_season_id_returns_seeded_value_as_int(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT OR IGNORE INTO {$p}prode_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                'prode_season_id',
                '359',
                $now
            )
        );

        $settings = new Settings( $wpdb );
        $this->assertSame( 359, $settings->seasonId() );
    }

    // -------------------------------------------------------------------------
    // Absent row — fallback to hardcoded default
    // -------------------------------------------------------------------------

    public function test_absent_row_returns_hardcoded_default(): void {
        global $wpdb;

        // No rows seeded (setUp cleared the table). Verify the accessor returns
        // the hardcoded default without error.
        $settings = new Settings( $wpdb );
        $this->assertSame( 359, $settings->seasonId() );
        $this->assertSame( 24, $settings->lockHoursBefore() );
        $this->assertSame( 1, $settings->fechaWindowDays() );
    }

    // -------------------------------------------------------------------------
    // Overridden value — UPDATE row, accessor returns new int
    // -------------------------------------------------------------------------

    public function test_overridden_value_is_returned_as_int(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        // Seed original value.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT OR IGNORE INTO {$p}prode_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                'lock_hours_before',
                '24',
                $now
            )
        );

        // Override to 48.
        $wpdb->update(
            $p . 'prode_settings',
            [ 'setting_value' => '48' ],
            [ 'setting_key' => 'lock_hours_before' ]
        );

        $settings = new Settings( $wpdb );
        $this->assertSame( 48, $settings->lockHoursBefore() );
    }
}
