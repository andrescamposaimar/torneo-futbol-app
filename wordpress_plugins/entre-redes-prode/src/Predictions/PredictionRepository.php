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
     * Aggregate populares percentages for all matches in a fecha.
     *
     * Runs a single GROUP BY query (no window functions — SQLite shim compatible).
     * Percentage computation is done in PHP after grouping by match_id.
     *
     * Returns a map: [ matchId => ['1' => float, 'X' => float, '2' => float] ]
     *   - Percentages are rounded to 1 decimal place.
     *   - All three result keys ('1', 'X', '2') are always present for each match.
     *   - A result with zero predictions for a match appears as 0.0.
     *   - Matches with NO predictions are absent from the returned map.
     *
     * Gate: callers are responsible for checking state — this method always runs
     * the query. The open/locked gate is enforced in FechaController and
     * FechaListController before calling this method.
     *
     * @param int $fechaId The prode_fechas.id to aggregate.
     * @return array<int, array{'1': float, 'X': float, '2': float}>
     */
    public function aggregatePopulares( int $fechaId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT match_id, result, COUNT(*) AS cnt
                   FROM {$p}prode_predictions
                  WHERE fecha_id = %d
                  GROUP BY match_id, result",
                $fechaId
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        // Group counts by match_id and compute totals.
        $counts = []; // match_id => [ result => count ]
        $totals = []; // match_id => total count

        foreach ( $rows as $row ) {
            $matchId = (int) $row['match_id'];
            $result  = (string) $row['result'];
            $cnt     = (int) $row['cnt'];

            $counts[ $matchId ][ $result ] = $cnt;
            $totals[ $matchId ]            = ( $totals[ $matchId ] ?? 0 ) + $cnt;
        }

        // Build percentages map with all three result keys per match.
        $map = [];
        foreach ( $counts as $matchId => $resultCounts ) {
            $total = $totals[ $matchId ];
            $map[ $matchId ] = [
                '1' => round( ( ( $resultCounts['1'] ?? 0 ) / $total ) * 100, 1 ),
                'X' => round( ( ( $resultCounts['X'] ?? 0 ) / $total ) * 100, 1 ),
                '2' => round( ( ( $resultCounts['2'] ?? 0 ) / $total ) * 100, 1 ),
            ];
        }

        return $map;
    }

    /**
     * Return all predictions submitted by a user for a given fecha, with
     * points and evaluation_method from prode_scores when available.
     *
     * Extends the previous SELECT with a LEFT JOIN on prode_scores so that
     * evaluated predictions carry `points` (int|null) and `evaluation_method`
     * (string|null). Rows without a matching prode_scores entry return null
     * for both columns (pre-evaluation or never-evaluated predictions).
     *
     * Design constraints:
     *   - LEFT JOIN on s.user_id + s.match_id (no window functions — SQLite shim compatible).
     *   - No wp_users JOIN.
     *   - SQLite shim: the join condition uses literal %d for user_id in the ON clause
     *     because SQLite-in-tests does not allow column references in the ON clause
     *     that reference the outer query's bind position — we bind user_id explicitly.
     *
     * @param int $fechaId The prode_fechas.id to filter by.
     * @param int $userId  The prode_users.id whose predictions to return.
     * @return array<int, array{match_id: int, score_home: int, score_away: int, points: int|null, evaluation_method: string|null}>
     */
    public function findByUserAndFecha( int $fechaId, int $userId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.match_id, p.score_home, p.score_away,
                        s.points, s.evaluation_method
                   FROM {$p}prode_predictions p
                   LEFT JOIN {$p}prode_scores s
                          ON s.user_id = p.user_id
                         AND s.match_id = p.match_id
                  WHERE p.fecha_id = %d AND p.user_id = %d",
                $fechaId,
                $userId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }
}
