<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * Typed accessor for Prode operator settings stored in prode_settings.
 *
 * Reads the key/value table for each getter and falls back to a hardcoded
 * default when the row is absent (belt-and-suspenders alongside the seeded
 * defaults from InitialSchema::seedSettings).
 *
 * All values are cast to int at the boundary — the table stores TEXT.
 */
class Settings {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Number of hours before the earliest kickoff when the fecha locks.
     * Default: 24.
     */
    public function lockHoursBefore(): int {
        return $this->readInt( 'lock_hours_before', 24 );
    }

    /**
     * The WordPress season post ID used for the active prediction game.
     * Default: 359.
     */
    public function seasonId(): int {
        return $this->readInt( 'prode_season_id', 359 );
    }

    /**
     * How many calendar days from the next play-date to include in one fecha.
     * Default: 1 (single matchday only).
     */
    public function fechaWindowDays(): int {
        return $this->readInt( 'fecha_window_days', 1 );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function readInt( string $key, int $default ): int {
        $p   = $this->wpdb->prefix;
        $sql = $this->wpdb->prepare(
            "SELECT setting_value FROM {$p}prode_settings WHERE setting_key = %s",
            $key
        );
        $value = $this->wpdb->get_var( $sql );

        if ( null === $value ) {
            return $default;
        }

        return (int) $value;
    }
}
