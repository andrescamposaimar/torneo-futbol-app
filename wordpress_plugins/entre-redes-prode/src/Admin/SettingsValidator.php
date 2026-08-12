<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Pure PHP validator for the Configuración settings form (D4).
 *
 * No wpdb dependency — fully testable without the WP shim.
 *
 * Rules enforced:
 *   - Integer fields: must be a non-empty string of digits (ctype_digit).
 *     Empty strings and non-numeric values are rejected (CONF-10).
 *   - Minimum value constraints per field.
 *   - lock_warning_hours_before must be strictly less than lock_hours_before
 *     (CONF-06 coherence check).
 *   - prode_google_client_id / prode_apple_audience: non-empty string when
 *     submitted and not constant-controlled.
 *   - Constant-override skip: when PRODE_GOOGLE_CLIENT_ID or PRODE_APPLE_AUDIENCE
 *     is defined as a PHP constant, the corresponding key is excluded from $clean
 *     entirely regardless of POST body contents (EDGE-02, D5).
 *   - All errors are aggregated; no partial writes on any error.
 *
 * @return array{clean: array<string, mixed>, errors: list<string>}
 */
final class SettingsValidator {

    /**
     * Integer fields with their minimum allowed value.
     * Keys must match the POST field names and prode_settings setting_key values.
     *
     * @var array<string, int>
     */
    private const INT_FIELDS = [
        'lock_hours_before'              => 0,
        'lock_warning_hours_before'      => 0,
        'fecha_window_days'              => 1,
        'prode_season_id'                => 1,
        'prode_ranking_from_fecha_id'    => 0,
        'evaluator_cron_interval_minutes' => 1,
    ];

    /**
     * String fields that must be non-empty when submitted (and not
     * constant-controlled).
     *
     * @var list<string>
     */
    private const STRING_FIELDS = [
        'prode_google_client_id',
        'prode_apple_audience',
    ];

    /**
     * Map of string field → constant name that overrides it (EDGE-02, D5).
     *
     * @var array<string, string>
     */
    private const CONSTANT_OVERRIDES = [
        'prode_google_client_id' => 'PRODE_GOOGLE_CLIENT_ID',
        'prode_apple_audience'   => 'PRODE_APPLE_AUDIENCE',
    ];

    /**
     * Validates a POST payload from the Configuración settings form.
     *
     * @param array<string, mixed> $post  The raw POST data ($_POST or equivalent).
     * @return array{clean: array<string, mixed>, errors: list<string>}
     */
    public static function validate( array $post ): array {
        $clean  = [];
        $errors = [];

        // ── Integer fields ────────────────────────────────────────────────────

        foreach ( self::INT_FIELDS as $key => $minValue ) {
            $raw = (string) ( $post[ $key ] ?? '' );

            if ( '' === $raw || ! ctype_digit( $raw ) ) {
                $errors[] = sprintf(
                    'El campo "%s" debe ser un número entero válido.',
                    $key
                );
                // Do NOT add to $clean — reject entirely (CONF-10).
                continue;
            }

            $value = (int) $raw;

            if ( $value < $minValue ) {
                $errors[] = sprintf(
                    'El campo "%s" debe ser mayor o igual a %d.',
                    $key,
                    $minValue
                );
                continue;
            }

            $clean[ $key ] = $value;
        }

        // ── Cross-field coherence: lock_warning < lock_hours ─────────────────
        // Only applies when both fields passed their individual validations.

        if ( isset( $clean['lock_warning_hours_before'], $clean['lock_hours_before'] ) ) {
            if ( $clean['lock_warning_hours_before'] >= $clean['lock_hours_before'] ) {
                $errors[] = 'El campo "lock_warning_hours_before" debe ser estrictamente menor que "lock_hours_before".';
                // Remove both from clean to prevent partial save.
                unset( $clean['lock_warning_hours_before'], $clean['lock_hours_before'] );
            }
        }

        // ── String fields ─────────────────────────────────────────────────────

        foreach ( self::STRING_FIELDS as $key ) {
            // Skip entirely when a PHP constant controls this value (EDGE-02).
            $constantName = self::CONSTANT_OVERRIDES[ $key ] ?? null;
            if ( $constantName !== null && defined( $constantName ) ) {
                // Exclude from $clean — do NOT write via update_option().
                continue;
            }

            $raw = trim( (string) ( $post[ $key ] ?? '' ) );

            if ( '' === $raw ) {
                $errors[] = sprintf(
                    'El campo "%s" no puede estar vacío.',
                    $key
                );
                continue;
            }

            $clean[ $key ] = $raw;
        }

        return [
            'clean'  => $clean,
            'errors' => $errors,
        ];
    }
}
