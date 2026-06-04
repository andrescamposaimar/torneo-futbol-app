<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\SettingsRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SettingsRepository (D1, TEST-01, CONF-01, CONF-02, CONF-03, EDGE-06).
 *
 * Runs against the in-memory SQLite shim. Each test resets the prode_settings
 * table via setUp/tearDown — mirrors SettingsTest pattern.
 */
class SettingsRepositoryTest extends TestCase {

    private SettingsRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );

        $this->repo = new SettingsRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up(); // restore seeds
    }

    // -------------------------------------------------------------------------
    // getSettings — TEST-01 / CONF-01
    // -------------------------------------------------------------------------

    public function test_getSettings_returns_all_five_keys(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        // Seed all 5 setting rows.
        $keys = [
            'lock_hours_before'               => '24',
            'lock_warning_hours_before'       => '2',
            'fecha_window_days'               => '1',
            'prode_season_id'                 => '359',
            'evaluator_cron_interval_minutes' => '5',
        ];

        foreach ( $keys as $key => $value ) {
            $wpdb->insert(
                $p . 'prode_settings',
                [
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'updated_at'    => $now,
                ]
            );
        }

        $settings = $this->repo->getSettings();

        $this->assertArrayHasKey( 'lock_hours_before', $settings );
        $this->assertArrayHasKey( 'lock_warning_hours_before', $settings );
        $this->assertArrayHasKey( 'fecha_window_days', $settings );
        $this->assertArrayHasKey( 'prode_season_id', $settings );
        $this->assertArrayHasKey( 'evaluator_cron_interval_minutes', $settings );
        $this->assertSame( '24', $settings['lock_hours_before'] );
        $this->assertSame( '2', $settings['lock_warning_hours_before'] );
    }

    // -------------------------------------------------------------------------
    // updateSetting — TEST-01 / CONF-07: writes updated_by + updated_at
    // -------------------------------------------------------------------------

    public function test_updateSetting_writes_updated_by_and_updated_at(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        // Seed the row first.
        $wpdb->insert(
            $p . 'prode_settings',
            [
                'setting_key'   => 'lock_hours_before',
                'setting_value' => '24',
                'updated_at'    => $now,
                'updated_by'    => null,
            ]
        );

        $result = $this->repo->updateSetting( 'lock_hours_before', '48', 7 );

        $this->assertTrue( $result );

        // Verify the DB row has the new value + actor.
        $row = $wpdb->get_row(
            "SELECT setting_value, updated_by FROM {$p}prode_settings WHERE setting_key = 'lock_hours_before'",
            ARRAY_A
        );

        $this->assertNotNull( $row );
        $this->assertSame( '48', $row['setting_value'] );
        $this->assertSame( '7', (string) $row['updated_by'] );
    }

    // -------------------------------------------------------------------------
    // getSettings — missing row returns empty array (caller falls back to Settings)
    // -------------------------------------------------------------------------

    public function test_missing_rows_return_empty_array(): void {
        // setUp cleared the table — no rows.
        $settings = $this->repo->getSettings();
        $this->assertSame( [], $settings );
    }

    // -------------------------------------------------------------------------
    // upsertSetting — EDGE-06: inserts when row absent
    // -------------------------------------------------------------------------

    public function test_upsertSetting_inserts_when_row_absent(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Confirm the row does not exist.
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}prode_settings WHERE setting_key = 'lock_hours_before'" );
        $this->assertSame( 0, $count );

        $result = $this->repo->upsertSetting( 'lock_hours_before', '24', 1 );

        $this->assertTrue( $result );

        $row = $wpdb->get_row(
            "SELECT setting_value, updated_by FROM {$p}prode_settings WHERE setting_key = 'lock_hours_before'",
            ARRAY_A
        );
        $this->assertNotNull( $row );
        $this->assertSame( '24', $row['setting_value'] );
        $this->assertSame( '1', (string) $row['updated_by'] );
    }

    // -------------------------------------------------------------------------
    // getProviderOptions — CONF-02, CONF-03: constant flag when defined
    // -------------------------------------------------------------------------

    public function test_getProviderOptions_returns_constant_flag_when_defined(): void {
        // PRODE_GOOGLE_CLIENT_ID was defined in SettingsValidatorTest; it persists.
        // We test the flag is reflected regardless of whether it was defined now or before.
        $opts = $this->repo->getProviderOptions();

        $this->assertArrayHasKey( 'google_client_id', $opts );
        $this->assertArrayHasKey( 'apple_audience', $opts );
        $this->assertArrayHasKey( 'google_constant', $opts );
        $this->assertArrayHasKey( 'apple_constant', $opts );

        // If PRODE_GOOGLE_CLIENT_ID is defined (set by SettingsValidatorTest),
        // the flag must be true.
        if ( defined( 'PRODE_GOOGLE_CLIENT_ID' ) ) {
            $this->assertTrue( $opts['google_constant'] );
        } else {
            $this->assertFalse( $opts['google_constant'] );
        }
    }
}
