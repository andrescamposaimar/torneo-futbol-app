<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

/**
 * Encapsulates all wpdb persistence for prode_scores.
 *
 * Upsert strategy (ADR-G3-6, mirrors PredictionRepository / ADR-G2-2):
 *   Before writing a score, SELECT the existing row for (user_id, match_id).
 *   If found, UPDATE it. If not found, INSERT. The operation runs inside a
 *   START TRANSACTION / COMMIT block.
 *
 *   INSERT … ON DUPLICATE KEY UPDATE is intentionally NOT used — the SQLite
 *   test shim cannot translate it. The code-level SELECT-then-INSERT/UPDATE is
 *   the authoritative dedup mechanism, mirroring PredictionRepository (ADR-G0-3).
 *
 * Schema note (TASK-0):
 *   prode_scores has a SINGLE timestamp column: evaluated_at.
 *   There is NO created_at and NO updated_at. evaluated_at is updated on every
 *   re-evaluation pass.
 *
 * SQLite shim quirk:
 *   get_var() returns a string even for numeric columns. All numeric reads
 *   must be cast with (int) before use (e.g. countUnscoredMatches).
 */
class ScoreRepository {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    // -------------------------------------------------------------------------
    // Table helper
    // -------------------------------------------------------------------------

    private function table(): string {
        return $this->wpdb->prefix . 'prode_scores';
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Idempotent upsert for a single (user_id, match_id) score row.
     *
     * - If no row exists for (user_id, match_id): INSERT all columns.
     * - If a row exists: UPDATE points, evaluation_method, prediction_id,
     *   and evaluated_at in place. The original insert is not duplicated.
     *
     * Both branches run inside START TRANSACTION / COMMIT (ADR-G3-6).
     *
     * @param int    $userId       prode_users.id of the scored user.
     * @param int    $fechaId      prode_fechas.id for the evaluated fecha.
     * @param int    $matchId      Match identifier.
     * @param int|null $predictionId prode_predictions.id (null for no_prediction rows).
     * @param int    $points       Points awarded [0, 3].
     * @param string $method       evaluation_method ENUM value.
     * @param string $evaluatedAt  Datetime string ('Y-m-d H:i:s').
     */
    public function upsertScore(
        int $userId,
        int $fechaId,
        int $matchId,
        ?int $predictionId,
        int $points,
        string $method,
        string $evaluatedAt
    ): void {
        $wpdb = $this->wpdb;

        $wpdb->query( 'START TRANSACTION' );

        // Step 1: check whether a row already exists for this (user_id, match_id).
        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE user_id = %d AND match_id = %d LIMIT 1",
                $userId,
                $matchId
            )
        );

        if ( null === $existingId ) {
            // Step 2a: no existing row — INSERT.
            $wpdb->insert(
                $this->table(),
                [
                    'user_id'           => $userId,
                    'fecha_id'          => $fechaId,
                    'match_id'          => $matchId,
                    'prediction_id'     => $predictionId,
                    'points'            => $points,
                    'evaluation_method' => $method,
                    'evaluated_at'      => $evaluatedAt,
                ]
            );
        } else {
            // Step 2b: row exists — UPDATE in place (evaluated_at is updated on every pass).
            $wpdb->update(
                $this->table(),
                [
                    'points'            => $points,
                    'evaluation_method' => $method,
                    'prediction_id'     => $predictionId,
                    'evaluated_at'      => $evaluatedAt,
                ],
                [ 'id' => (int) $existingId ]
            );
        }

        $wpdb->query( 'COMMIT' );
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all prode_scores rows for the given fecha_id.
     *
     * Returns an empty array when no rows match.
     *
     * @param int $fechaId
     * @return array<int, array<string, mixed>>
     */
    public function findByFecha( int $fechaId ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE fecha_id = %d",
                $fechaId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Count distinct match_ids in prode_scores for the fecha that still have
     * evaluation_method = 'no_match_score'.
     *
     * Returns 0 when all matches have a final evaluated score, which is the
     * gate condition for flipping prode_fechas.state → 'evaluated' (ADR-G3-7).
     *
     * Cast to int is mandatory — wpdb::get_var() returns a string even for
     * numeric results (SQLite shim quirk noted in design §B).
     *
     * @param int $fechaId
     * @return int
     */
    public function countUnscoredMatches( int $fechaId ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(DISTINCT match_id) FROM {$this->table()}
                  WHERE fecha_id = %d AND evaluation_method = 'no_match_score'",
                $fechaId
            )
        );
    }
}
