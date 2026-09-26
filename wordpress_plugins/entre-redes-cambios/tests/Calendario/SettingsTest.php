<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Settings against the in-memory SQLite shim.
 *
 * Settings was the only business class in this slice with no direct test
 * coverage: every getter reads `cambios_settings` and falls back to
 * InitialSchema::SEED_DEFAULTS when the row is absent, and readOffset() also
 * has to survive a corrupted or partial JSON value. Both failure modes are
 * silent by design (a wrong fallback just quietly returns a different value,
 * never an error), so this file is what actually catches a typo in a
 * setting_key string or a mismatched JSON shape.
 */
class SettingsTest extends TestCase {

    private Settings $settings;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );

        $this->settings = new Settings( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );
        InitialSchema::up(); // restore seeds for any test that runs after this file
    }

    private function putSetting( string $key, string $value ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$p}cambios_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                $key,
                $value,
                '2026-01-01 00:00:00'
            )
        );
    }

    // -------------------------------------------------------------------------
    // timezone()
    // -------------------------------------------------------------------------

    public function test_timezone_returns_the_row_value_when_present(): void {
        $this->putSetting( 'timezone', 'America/Sao_Paulo' );

        $this->assertSame( 'America/Sao_Paulo', $this->settings->timezone() );
    }

    public function test_timezone_falls_back_to_the_default_when_the_row_is_absent(): void {
        $this->assertSame( InitialSchema::SEED_DEFAULTS['timezone'], $this->settings->timezone() );
    }

    // -------------------------------------------------------------------------
    // seasonId()
    // -------------------------------------------------------------------------

    public function test_season_id_returns_the_row_value_when_present(): void {
        $this->putSetting( 'season_id', '999' );

        $this->assertSame( 999, $this->settings->seasonId() );
    }

    public function test_season_id_falls_back_to_the_default_when_the_row_is_absent(): void {
        $this->assertSame( (int) InitialSchema::SEED_DEFAULTS['season_id'], $this->settings->seasonId() );
    }

    // -------------------------------------------------------------------------
    // The four plazo offsets — row present
    // -------------------------------------------------------------------------

    public function test_apertura_solicitudes_offset_returns_the_row_value_when_present(): void {
        $this->putSetting( 'plazo_apertura_solicitudes', '{"days":-7,"time":"08:00:00"}' );

        $this->assertSame(
            [ 'days' => -7, 'time' => '08:00:00' ],
            $this->settings->aperturaSolicitudesOffset()
        );
    }

    public function test_cierre_regresos_offset_returns_the_row_value_when_present(): void {
        $this->putSetting( 'plazo_cierre_regresos', '{"days":-5,"time":"12:00:00"}' );

        $this->assertSame(
            [ 'days' => -5, 'time' => '12:00:00' ],
            $this->settings->cierreRegresosOffset()
        );
    }

    public function test_cierre_solicitudes_offset_returns_the_row_value_when_present(): void {
        $this->putSetting( 'plazo_cierre_solicitudes', '{"days":-3,"time":"18:00:00"}' );

        $this->assertSame(
            [ 'days' => -3, 'time' => '18:00:00' ],
            $this->settings->cierreSolicitudesOffset()
        );
    }

    public function test_publicacion_offset_returns_the_row_value_when_present(): void {
        $this->putSetting( 'plazo_publicacion', '{"days":-2,"time":"06:00:00"}' );

        $this->assertSame(
            [ 'days' => -2, 'time' => '06:00:00' ],
            $this->settings->publicacionOffset()
        );
    }

    // -------------------------------------------------------------------------
    // readOffset() edge cases — absent row, corrupted JSON, missing keys
    // -------------------------------------------------------------------------

    public function test_offset_falls_back_to_the_default_when_the_row_is_absent(): void {
        $expected = json_decode( InitialSchema::SEED_DEFAULTS['plazo_apertura_solicitudes'], true );

        $this->assertSame(
            [ 'days' => (int) $expected['days'], 'time' => (string) $expected['time'] ],
            $this->settings->aperturaSolicitudesOffset()
        );
    }

    public function test_offset_falls_back_to_the_default_when_the_json_is_corrupted(): void {
        $this->putSetting( 'plazo_cierre_regresos', 'not valid json {{{' );

        $expected = json_decode( InitialSchema::SEED_DEFAULTS['plazo_cierre_regresos'], true );

        $this->assertSame(
            [ 'days' => (int) $expected['days'], 'time' => (string) $expected['time'] ],
            $this->settings->cierreRegresosOffset()
        );
    }

    public function test_offset_falls_back_to_the_default_when_days_is_missing(): void {
        $this->putSetting( 'plazo_cierre_solicitudes', '{"time":"23:59:59"}' );

        $expected = json_decode( InitialSchema::SEED_DEFAULTS['plazo_cierre_solicitudes'], true );

        $this->assertSame(
            [ 'days' => (int) $expected['days'], 'time' => (string) $expected['time'] ],
            $this->settings->cierreSolicitudesOffset()
        );
    }

    public function test_offset_falls_back_to_the_default_when_time_is_missing(): void {
        $this->putSetting( 'plazo_publicacion', '{"days":-1}' );

        $expected = json_decode( InitialSchema::SEED_DEFAULTS['plazo_publicacion'], true );

        $this->assertSame(
            [ 'days' => (int) $expected['days'], 'time' => (string) $expected['time'] ],
            $this->settings->publicacionOffset()
        );
    }

    // -------------------------------------------------------------------------
    // plazosOffsets() aggregator
    // -------------------------------------------------------------------------

    public function test_plazos_offsets_returns_all_four_keyed_for_plazos_calculator(): void {
        $this->putSetting( 'plazo_apertura_solicitudes', '{"days":-6,"time":"00:00:00"}' );
        $this->putSetting( 'plazo_cierre_regresos', '{"days":-4,"time":"23:59:59"}' );
        $this->putSetting( 'plazo_cierre_solicitudes', '{"days":-2,"time":"23:59:59"}' );
        $this->putSetting( 'plazo_publicacion', '{"days":-1,"time":"00:00:00"}' );

        $this->assertSame(
            [
                'apertura_solicitudes' => [ 'days' => -6, 'time' => '00:00:00' ],
                'cierre_regresos'      => [ 'days' => -4, 'time' => '23:59:59' ],
                'cierre_solicitudes'   => [ 'days' => -2, 'time' => '23:59:59' ],
                'publicacion'          => [ 'days' => -1, 'time' => '00:00:00' ],
            ],
            $this->settings->plazosOffsets()
        );
    }

    // -------------------------------------------------------------------------
    // Drift protection: every getter's fallback vs InitialSchema::SEED_DEFAULTS
    // (mirrors entre-redes-prode's SettingsKeyConsistencyTest)
    // -------------------------------------------------------------------------

    public function test_every_default_falls_back_to_exactly_what_initial_schema_seeds(): void {
        $this->assertSame( InitialSchema::SEED_DEFAULTS['timezone'], $this->settings->timezone() );
        $this->assertSame( (int) InitialSchema::SEED_DEFAULTS['season_id'], $this->settings->seasonId() );

        $offsetGetters = [
            'plazo_apertura_solicitudes' => fn () => $this->settings->aperturaSolicitudesOffset(),
            'plazo_cierre_regresos'      => fn () => $this->settings->cierreRegresosOffset(),
            'plazo_cierre_solicitudes'   => fn () => $this->settings->cierreSolicitudesOffset(),
            'plazo_publicacion'          => fn () => $this->settings->publicacionOffset(),
        ];

        foreach ( $offsetGetters as $seedKey => $getter ) {
            $expected = json_decode( InitialSchema::SEED_DEFAULTS[ $seedKey ], true );

            $this->assertSame(
                [ 'days' => (int) $expected['days'], 'time' => (string) $expected['time'] ],
                $getter(),
                "Settings' fallback for '{$seedKey}' has drifted from InitialSchema::SEED_DEFAULTS."
            );
        }
    }
}
