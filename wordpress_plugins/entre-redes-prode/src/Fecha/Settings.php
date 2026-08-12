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
     * Lowest fecha id counted by the cumulative (season) leaderboard.
     *
     * One SportsPress season carries several tournaments — Apertura and Clausura
     * both live under season 359 — and each should start with an empty table.
     * Raising this to the tournament's first fecha resets the standings without
     * touching a single prediction or score: the rows stay in `prode_scores`, the
     * per-fecha tables for earlier fechas keep rendering, and lowering it again
     * brings the combined table back.
     *
     * Default: 0 — count every fecha in the season.
     */
    public function rankingFromFechaId(): int {
        return $this->readInt( 'prode_ranking_from_fecha_id', 0 );
    }

    /**
     * How many calendar days from the next play-date to include in one fecha.
     * Default: 1 (single matchday only).
     */
    public function fechaWindowDays(): int {
        return $this->readInt( 'fecha_window_days', 1 );
    }

    /**
     * Number of hours before the lock when a warning notification is sent.
     * Default: 2.
     */
    public function lockWarningHoursBefore(): int {
        return $this->readInt( 'lock_warning_hours_before', 2 );
    }

    /**
     * Interval in minutes between evaluator cron runs.
     * Default: 5.
     */
    public function evaluatorCronIntervalMinutes(): int {
        return $this->readInt( 'evaluator_cron_interval_minutes', 5 );
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
