<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Predictions;

/**
 * Encapsulates all wpdb persistence for prode_predictions.
 *
 * Upsert strategy (ADR-G2-2):
 *   Before writing a prediction, SELECT the existing row for (user_id, match_id).
 *   If found, UPDATE it. If not found, INSERT. The operation runs inside a
 *   START TRANSACTION / COMMIT block.
 *
 *   INSERT … ON DUPLICATE KEY UPDATE is intentionally NOT used — the SQLite
 *   test shim cannot translate it, which would cause silent failures in tests.
 *   The code-level SELECT-then-INSERT/UPDATE is the authoritative dedup
 *   mechanism, mirroring FechaRepository (ADR-G0-3).
 *
 * Result derivation (ADR-G2-1):
 *   The `result` field ('1', 'X', '2') is always derived server-side from the
 *   submitted scores. The client never sends `result`.
 */
class PredictionRepository {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Derive the 1X2 result string from home and away scores.
     *
     * home > away → '1', home == away → 'X', home < away → '2'.
     *
     * Exposed as public so it can be tested directly. The logic is pure (no
     * side effects) and small enough to live on the repository rather than a
     * separate value object (ADR-G2-1).
     */
    public function deriveResult( int $scoreHome, int $scoreAway ): string {
        if ( $scoreHome > $scoreAway ) {
            return '1';
        }
        if ( $scoreHome === $scoreAway ) {
            return 'X';
        }
        return '2';
    }

    /**
     * Upsert a prediction for a (user, match) pair.
     *
     * - If no row exists for (user_id, match_id): INSERT with created_at set.
     * - If a row already exists: UPDATE score_home, score_away, result,
     *   updated_at, and locked_at_snapshot. created_at is never touched.
     *
     * Both branches run inside START TRANSACTION / COMMIT.
     *
     * @param int    $userId           The prode_users.id of the predicting user.
     * @param int    $fechaId          The prode_fechas.id for the active fecha.
     * @param int    $matchId          The match identifier.
     * @param int    $scoreHome        Predicted home score [0, 255].
     * @param int    $scoreAway        Predicted away score [0, 255].
     * @param string $lockedAtSnapshot The prode_fechas.locked_at value snapshotted at write time.
     */
    public function upsert(
        int $userId,
        int $fechaId,
        int $matchId,
        int $scoreHome,
        int $scoreAway,
        string $lockedAtSnapshot
    ): void {
        $wpdb   = $this->wpdb;
        $p      = $wpdb->prefix;
        $result = $this->deriveResult( $scoreHome, $scoreAway );
        $now    = current_time( 'mysql' );

        $wpdb->query( 'START TRANSACTION' );

        // Step 1: check whether a row already exists for this (user_id, match_id).
        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$p}prode_predictions
                  WHERE user_id = %d AND match_id = %d
                  LIMIT 1",
                $userId,
                $matchId
            )
        );

        if ( null === $existingId ) {
            // Step 2a: no existing row — INSERT.
            $wpdb->insert(
                $p . 'prode_predictions',
                [
                    'user_id'            => $userId,
                    'fecha_id'           => $fechaId,
                    'match_id'           => $matchId,
                    'result'             => $result,
                    'score_home'         => $scoreHome,
                    'score_away'         => $scoreAway,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                    'locked_at_snapshot' => $lockedAtSnapshot,
                ]
            );
        } else {
            // Step 2b: row exists — UPDATE (created_at intentionally excluded).
            $wpdb->update(
                $p . 'prode_predictions',
                [
                    'result'             => $result,
                    'score_home'         => $scoreHome,
                    'score_away'         => $scoreAway,
                    'updated_at'         => $now,
                    'locked_at_snapshot' => $lockedAtSnapshot,
                ],
                [ 'id' => (int) $existingId ]
            );
        }

        $wpdb->query( 'COMMIT' );
    }

    /**
     * Return all predictions submitted by a user for a given fecha.
     *
     * Used by FechaController to back-populate user_predictions in the GET
     * /prode/fecha-activa response (WU-A2).
     *
     * @param int $fechaId The prode_fechas.id to filter by.
     * @param int $userId  The prode_users.id whose predictions to return.
     * @return array<int, array{match_id: int, score_home: int, score_away: int}>
     */
    public function findByUserAndFecha( int $fechaId, int $userId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT match_id, score_home, score_away
                   FROM {$p}prode_predictions
                  WHERE fecha_id = %d AND user_id = %d",
                $fechaId,
                $userId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }
}
