<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two new Settings getters (SET-01, SET-02, CC-07).
 *
 * Tests follow the same pattern as SettingsTest: clear the table in setUp,
 * restore seeds in tearDown to keep the shared SQLite DB consistent.
 */
class SettingsNewGettersTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up();
    }

    // -------------------------------------------------------------------------
    // lockWarningHoursBefore — SET-01
    // -------------------------------------------------------------------------

    public function test_lock_warning_hours_before_returns_default_when_absent(): void {
        global $wpdb;
        $settings = new Settings( $wpdb );
        $this->assertSame( 2, $settings->lockWarningHoursBefore() );
    }

    public function test_lock_warning_hours_before_returns_seeded_value(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT OR IGNORE INTO {$p}prode_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                'lock_warning_hours_before',
                '6',
                $now
            )
        );

        $settings = new Settings( $wpdb );
        $this->assertSame( 6, $settings->lockWarningHoursBefore() );
    }

    // -------------------------------------------------------------------------
    // evaluatorCronIntervalMinutes — SET-02
    // -------------------------------------------------------------------------

    public function test_evaluator_cron_interval_minutes_returns_default_when_absent(): void {
        global $wpdb;
        $settings = new Settings( $wpdb );
        $this->assertSame( 5, $settings->evaluatorCronIntervalMinutes() );
    }

    public function test_evaluator_cron_interval_minutes_returns_seeded_value(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT OR IGNORE INTO {$p}prode_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                'evaluator_cron_interval_minutes',
                '15',
                $now
            )
        );

        $settings = new Settings( $wpdb );
        $this->assertSame( 15, $settings->evaluatorCronIntervalMinutes() );
    }
}
