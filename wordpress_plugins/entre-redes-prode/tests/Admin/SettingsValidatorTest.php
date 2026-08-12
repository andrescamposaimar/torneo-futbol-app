<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\SettingsValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SettingsValidator (D4, CONF-06, CONF-10, EDGE-02).
 *
 * Pure class — no wpdb, no shim required. Standard TestCase is sufficient.
 *
 * Tests cover:
 *   - Valid payload → no errors
 *   - Non-numeric integer field rejected (CONF-10)
 *   - lock_warning_hours_before >= lock_hours_before rejected (CONF-06 coherence)
 *   - Empty string for Google Client ID rejected
 *   - Constant-override excludes prode_google_client_id from clean (EDGE-02)
 *   - Constant-override excludes prode_apple_audience from clean
 *   - Multiple errors returned together
 */
class SettingsValidatorTest extends TestCase {

    private function validPayload(): array {
        return [
            'lock_hours_before'              => '24',
            'lock_warning_hours_before'      => '2',
            'fecha_window_days'              => '1',
            'prode_season_id'                => '359',
            'prode_ranking_from_fecha_id'    => '0',
            'evaluator_cron_interval_minutes' => '5',
            'prode_google_client_id'         => 'some-google-client-id',
            'prode_apple_audience'           => 'some-apple-audience',
        ];
    }

    // -------------------------------------------------------------------------

    public function test_valid_payload_returns_no_errors(): void {
        $result = SettingsValidator::validate( $this->validPayload() );

        $this->assertArrayHasKey( 'clean', $result );
        $this->assertArrayHasKey( 'errors', $result );
        $this->assertEmpty( $result['errors'], 'Expected no validation errors for a valid payload.' );
        $this->assertSame( 24, $result['clean']['lock_hours_before'] );
        $this->assertSame( 2, $result['clean']['lock_warning_hours_before'] );
        $this->assertSame( 1, $result['clean']['fecha_window_days'] );
        $this->assertSame( 359, $result['clean']['prode_season_id'] );
        // Zero is a meaningful value here — "count the whole season" — unlike the
        // other int fields, whose minimum is 1.
        $this->assertSame( 0, $result['clean']['prode_ranking_from_fecha_id'] );
        $this->assertSame( 5, $result['clean']['evaluator_cron_interval_minutes'] );
        $this->assertSame( 'some-google-client-id', $result['clean']['prode_google_client_id'] );
        $this->assertSame( 'some-apple-audience', $result['clean']['prode_apple_audience'] );
    }

    public function test_non_numeric_integer_field_is_rejected(): void {
        $post            = $this->validPayload();
        $post['fecha_window_days'] = 'abc';

        $result = SettingsValidator::validate( $post );

        $this->assertNotEmpty( $result['errors'] );
        // Must NOT silently convert to 0.
        $this->assertArrayNotHasKey( 'fecha_window_days', $result['clean'] );
    }

    public function test_lock_warning_must_be_less_than_lock_hours(): void {
        $post                                 = $this->validPayload();
        $post['lock_warning_hours_before']    = '24'; // equal to lock_hours_before → invalid
        $post['lock_hours_before']            = '24';

        $result = SettingsValidator::validate( $post );

        $this->assertNotEmpty( $result['errors'] );
        $errorText = implode( ' ', $result['errors'] );
        $this->assertStringContainsStringIgnoringCase( 'lock_warning_hours_before', $errorText );
    }

    public function test_empty_string_google_client_id_is_rejected(): void {
        $post                        = $this->validPayload();
        $post['prode_google_client_id'] = '';

        $result = SettingsValidator::validate( $post );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertArrayNotHasKey( 'prode_google_client_id', $result['clean'] );
    }

    public function test_constant_override_excludes_google_client_id_from_clean(): void {
        if ( ! defined( 'PRODE_GOOGLE_CLIENT_ID' ) ) {
            define( 'PRODE_GOOGLE_CLIENT_ID', 'constant-value' );
        }

        $post = $this->validPayload();
        $post['prode_google_client_id'] = 'crafted-value';

        $result = SettingsValidator::validate( $post );

        $this->assertArrayNotHasKey(
            'prode_google_client_id',
            $result['clean'],
            'Constant-controlled key must be excluded from clean regardless of POST value.'
        );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_constant_override_excludes_apple_audience_from_clean(): void {
        if ( ! defined( 'PRODE_APPLE_AUDIENCE' ) ) {
            define( 'PRODE_APPLE_AUDIENCE', 'constant-apple' );
        }

        $post = $this->validPayload();
        $post['prode_apple_audience'] = 'crafted-apple';

        $result = SettingsValidator::validate( $post );

        $this->assertArrayNotHasKey(
            'prode_apple_audience',
            $result['clean'],
            'Constant-controlled key must be excluded from clean regardless of POST value.'
        );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_multiple_errors_returned_together(): void {
        $post = $this->validPayload();
        $post['lock_hours_before']               = 'not-a-number';
        $post['evaluator_cron_interval_minutes']  = '';
        $post['prode_google_client_id']           = '';

        $result = SettingsValidator::validate( $post );

        // At least 2 non-constant fields are invalid (lock_hours_before and
        // evaluator_cron_interval_minutes). prode_google_client_id may be
        // skipped when PRODE_GOOGLE_CLIENT_ID is defined as a constant
        // (test ordering within the same process can leave constants defined).
        $this->assertGreaterThanOrEqual( 2, count( $result['errors'] ) );
    }
}
