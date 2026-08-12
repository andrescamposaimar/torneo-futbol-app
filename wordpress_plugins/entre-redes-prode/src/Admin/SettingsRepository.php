<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Encapsulates all wpdb persistence for prode_settings rows and the two
 * WP options backed by provider credentials.
 *
 * Design notes (D1, D5):
 *   - Constructor receives \wpdb; mirrors FechaRepository / RankingRepository.
 *   - Constant-override skip enforced at the write layer (D5): if
 *     PRODE_GOOGLE_CLIENT_ID or PRODE_APPLE_AUDIENCE are defined as PHP
 *     constants, updateProviderOption() silently skips the write for those
 *     keys — the server-side guard that complements the read-only HTML rendering.
 *   - upsertSetting() handles EDGE-06 (missing prode_settings row): tries
 *     UPDATE first; if 0 rows affected, falls back to INSERT.
 */
class SettingsRepository {

    /**
     * The editable setting_keys (CONF-01).
     *
     * Public because it is the single source of truth for both reading and
     * writing these rows. SettingsPage::handleSave used to keep its own copy of
     * this list, so a setting added everywhere else silently failed to persist:
     * it validated, entered $clean, and was then skipped by the write loop.
     *
     * @var array<string>
     */
    public const SETTING_KEYS = [
        'lock_hours_before',
        'lock_warning_hours_before',
        'fecha_window_days',
        'prode_season_id',
        'prode_ranking_from_fecha_id',
        'evaluator_cron_interval_minutes',
    ];

    public function __construct( private \wpdb $wpdb ) {}

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Returns an associative array of setting_key => setting_value for all
     * editable prode_settings rows that are present in the DB.
     *
     * Missing rows are simply absent from the returned array; the caller must
     * fall back to Settings::readInt() defaults (CONF-01, EDGE-06).
     *
     * @return array<string, string>
     */
    public function getSettings(): array {
        $p    = $this->wpdb->prefix;
        $keys = self::SETTING_KEYS;

        $placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT setting_key, setting_value, updated_at, updated_by
                   FROM {$p}prode_settings
                  WHERE setting_key IN ({$placeholders})",
                ...$keys
            ),
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row['setting_key'] ] = $row['setting_value'];
        }

        return $map;
    }

    /**
     * Returns the full row (including updated_at and updated_by) for all five
     * editable settings. Useful for displaying metadata on the settings form
     * (EDGE-01).
     *
     * @return array<string, array<string, mixed>> key => {setting_value, updated_at, updated_by}
     */
    public function getSettingsWithMeta(): array {
        $p    = $this->wpdb->prefix;
        $keys = self::SETTING_KEYS;

        $placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT setting_key, setting_value, updated_at, updated_by
                   FROM {$p}prode_settings
                  WHERE setting_key IN ({$placeholders})",
                ...$keys
            ),
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row['setting_key'] ] = [
                'setting_value' => $row['setting_value'],
                'updated_at'    => $row['updated_at'],
                'updated_by'    => $row['updated_by'],
            ];
        }

        return $map;
    }

    /**
     * Returns an array with the two provider option values plus boolean flags
     * indicating whether each is controlled by a PHP constant (D5, CONF-03).
     *
     * Shape: [
     *   'google_client_id' => string,
     *   'apple_audience'   => string,
     *   'google_constant'  => bool,
     *   'apple_constant'   => bool,
     * ]
     *
     * @return array<string, mixed>
     */
    public function getProviderOptions(): array {
        return [
            'google_client_id' => (string) get_option( 'prode_google_client_id', '' ),
            'apple_audience'   => (string) get_option( 'prode_apple_audience', '' ),
            'google_constant'  => defined( 'PRODE_GOOGLE_CLIENT_ID' ),
            'apple_constant'   => defined( 'PRODE_APPLE_AUDIENCE' ),
        ];
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Updates an existing prode_settings row, stamping updated_at and
     * updated_by (CONF-07).
     *
     * Returns true when the DB row was updated (rowCount > 0), false otherwise.
     * For the upsert pattern (EDGE-06), prefer upsertSetting().
     */
    public function updateSetting( string $key, string $value, int $actorWpId ): bool {
        $p      = $this->wpdb->prefix;
        $result = $this->wpdb->update(
            $p . 'prode_settings',
            [
                'setting_value' => $value,
                'updated_at'    => current_time( 'mysql' ),
                'updated_by'    => $actorWpId,
            ],
            [ 'setting_key' => $key ]
        );

        return $result !== false && $result > 0;
    }

    /**
     * Upserts a prode_settings row: UPDATE if row exists, INSERT if absent
     * (EDGE-06: migration may have left a row missing).
     *
     * Returns true on success, false on DB error.
     */
    public function upsertSetting( string $key, string $value, int $actorWpId ): bool {
        $now    = current_time( 'mysql' );
        $p      = $this->wpdb->prefix;

        // Try UPDATE first.
        $updated = $this->wpdb->update(
            $p . 'prode_settings',
            [
                'setting_value' => $value,
                'updated_at'    => $now,
                'updated_by'    => $actorWpId,
            ],
            [ 'setting_key' => $key ]
        );

        if ( $updated === false ) {
            return false; // DB error.
        }

        if ( $updated > 0 ) {
            return true; // Row existed and was updated.
        }

        // Row absent → INSERT (EDGE-06).
        $result = $this->wpdb->insert(
            $p . 'prode_settings',
            [
                'setting_key'   => $key,
                'setting_value' => $value,
                'updated_at'    => $now,
                'updated_by'    => $actorWpId,
            ]
        );

        return $result !== false;
    }

    /**
     * Updates a WP option for one of the two provider credential keys.
     *
     * Constant-override skip (D5): if PRODE_GOOGLE_CLIENT_ID or
     * PRODE_APPLE_AUDIENCE is defined as a PHP constant, this method silently
     * returns true without writing to the DB — server-side enforcement that
     * complements the read-only HTML rendering in the form.
     *
     * @param string $key   One of: 'prode_google_client_id', 'prode_apple_audience'.
     * @param string $value Sanitised value from SettingsValidator.
     */
    public function updateProviderOption( string $key, string $value ): bool {
        // Constant-override skip (D5).
        if ( $key === 'prode_google_client_id' && defined( 'PRODE_GOOGLE_CLIENT_ID' ) ) {
            return true;
        }
        if ( $key === 'prode_apple_audience' && defined( 'PRODE_APPLE_AUDIENCE' ) ) {
            return true;
        }

        return update_option( $key, $value );
    }
}
