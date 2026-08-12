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
     * Aggregate popular percentages for a single match.
     *
     * Counterpart of aggregatePopulares(), which works per fecha. The match
     * detail screen knows a match id but not which fecha holds it, so this
     * reads by match and reports back whether any fecha containing it is still
     * open.
     *
     * Semantics:
     *   - Percentages are rounded to 1 decimal place.
     *   - All three result keys ('1', 'X', '2') are always present.
     *   - A match with no predictions returns total 0 and all keys at 0.0.
     *   - `open` is true when at least one fecha holding this match is still
     *     accepting predictions.
     *
     * Gate: the caller decides what to do with `open` — this method always runs
     * the query. Mirrors the contract of aggregatePopulares().
     *
     * @param int $matchId The sp_event id predictions were stored against.
     * @return array{total: int, open: bool, populares: array{'1': float, 'X': float, '2': float}}
     */
    public function aggregateForMatch( int $matchId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.result AS result, COUNT(*) AS cnt, f.state AS state
                   FROM {$p}prode_predictions p
                   INNER JOIN {$p}prode_fechas f ON f.id = p.fecha_id
                  WHERE p.match_id = %d
                  GROUP BY p.result, f.state",
                $matchId
            ),
            ARRAY_A
        );

        $counts = [ '1' => 0, 'X' => 0, '2' => 0 ];
        $total  = 0;
        $open   = false;

        foreach ( (array) $rows as $row ) {
            $result = (string) ( $row['result'] ?? '' );
            $cnt    = (int) ( $row['cnt'] ?? 0 );

            if ( ! array_key_exists( $result, $counts ) ) {
                continue;
            }

            $counts[ $result ] += $cnt;
            $total             += $cnt;

            if ( 'open' === (string) ( $row['state'] ?? '' ) ) {
                $open = true;
            }
        }

        if ( 0 === $total ) {
            return [
                'total'     => 0,
                'open'      => $open,
                'populares' => [ '1' => 0.0, 'X' => 0.0, '2' => 0.0 ],
            ];
        }

        return [
            'total'     => $total,
            'open'      => $open,
            'populares' => [
                '1' => round( ( $counts['1'] / $total ) * 100, 1 ),
                'X' => round( ( $counts['X'] / $total ) * 100, 1 ),
                '2' => round( ( $counts['2'] / $total ) * 100, 1 ),
            ],
        ];
    }

    /**
     * Return all predictions across all fechas for a given user, joined with
     * match metadata and evaluation results.
     *
     * Used by the admin Predictions page (capability B — PR-3).
     *
     * JOIN strategy:
     *   - LEFT JOIN prode_fecha_matches on (fecha_id + match_id) for home_team,
     *     away_team, real_score_home/away, is_final.
     *   - LEFT JOIN prode_scores on (user_id + match_id) for points and
     *     evaluation_method.
     *
     * No wp_users JOIN (design constraint).
     * ORDER BY fecha_id ASC, match_id ASC (spec: per-user detail sort order).
     *
     * @param int $userId The prode_users.id to query.
     * @return array<int, array<string, mixed>>
     */
    public function findAllByUser( int $userId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.fecha_id,
                        p.match_id,
                        fm.home_team,
                        fm.away_team,
                        p.score_home,
                        p.score_away,
                        fm.real_score_home,
                        fm.real_score_away,
                        fm.is_final,
                        s.points,
                        s.evaluation_method
                   FROM {$p}prode_predictions p
                   LEFT JOIN {$p}prode_fecha_matches fm
                          ON fm.fecha_id = p.fecha_id
                         AND fm.match_id = p.match_id
                   LEFT JOIN {$p}prode_scores s
                          ON s.user_id = p.user_id
                         AND s.match_id = p.match_id
                  WHERE p.user_id = %d
                  ORDER BY p.fecha_id ASC, p.match_id ASC",
                $userId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Return the total number of predictions for a given user across all fechas.
     *
     * Used for pagination in the admin Predictions page (capability B — PR-3).
     *
     * @param int $userId The prode_users.id to count.
     * @return int
     */
    public function countByUser( int $userId ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}prode_predictions WHERE user_id = %d",
                $userId
            )
        );

        return (int) $count;
    }

    /**
     * Return a paginated slice of a user's predictions for FINISHED matches,
     * ordered most-recent-first, joined with match snapshot metadata and
     * evaluation results.
     *
     * "Finished" means the match has a final result recorded
     * (prode_fecha_matches.is_final = 1). This powers the app's "Anteriores"
     * (past predictions) infinite-scroll list. Upcoming/open matches are served
     * by the per-fecha fixtures endpoints, not here.
     *
     * JOIN strategy:
     *   - LEFT JOIN prode_fecha_matches for the snapshot (kickoff, teams, zona,
     *     escudos, real scores, is_final). The is_final = 1 filter in WHERE makes
     *     this effectively an inner join — predictions without a final match row
     *     are excluded.
     *   - JOIN prode_fechas for season_id (used by the client to render the
     *     "{season} - {zona}" header line).
     *   - LEFT JOIN prode_scores for points + evaluation_method (null when the
     *     fecha is final but not yet evaluated).
     *
     * Ordering: match_kickoff DESC, match_id DESC (most recent first; match_id is
     * a stable tiebreak for matches sharing a kickoff time).
     *
     * No wp_users JOIN. No window functions. SQLite-shim compatible.
     *
     * @param int $userId The prode_users.id to query.
     * @param int $limit  Page size (LIMIT).
     * @param int $offset Row offset (OFFSET).
     * @return array<int, array<string, mixed>>
     */
    public function findFinishedByUserPaginated( int $userId, int $limit, int $offset ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.fecha_id,
                        p.match_id,
                        f.season_id,
                        fm.match_kickoff,
                        fm.home_team,
                        fm.away_team,
                        fm.zona,
                        fm.home_escudo,
                        fm.away_escudo,
                        p.score_home,
                        p.score_away,
                        fm.real_score_home,
                        fm.real_score_away,
                        fm.is_final,
                        s.points,
                        s.evaluation_method
                   FROM {$p}prode_predictions p
                   LEFT JOIN {$p}prode_fecha_matches fm
                          ON fm.fecha_id = p.fecha_id
                         AND fm.match_id = p.match_id
                   LEFT JOIN {$p}prode_fechas f
                          ON f.id = p.fecha_id
                   LEFT JOIN {$p}prode_scores s
                          ON s.user_id = p.user_id
                         AND s.match_id = p.match_id
                  WHERE p.user_id = %d
                    AND fm.is_final = 1
                  ORDER BY fm.match_kickoff DESC, fm.match_id DESC
                  LIMIT %d OFFSET %d",
                $userId,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Count a user's predictions for FINISHED matches (is_final = 1).
     *
     * Used for pagination of the "Anteriores" history list. Mirrors the WHERE
     * clause of findFinishedByUserPaginated so totals and pages stay consistent.
     *
     * @param int $userId The prode_users.id to count.
     * @return int
     */
    public function countFinishedByUser( int $userId ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                   FROM {$p}prode_predictions p
                   LEFT JOIN {$p}prode_fecha_matches fm
                          ON fm.fecha_id = p.fecha_id
                         AND fm.match_id = p.match_id
                  WHERE p.user_id = %d
                    AND fm.is_final = 1",
                $userId
            )
        );

        return (int) $count;
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
     *   - The ON clause joins on column references (s.user_id = p.user_id AND
     *     s.match_id = p.match_id), not a literal user_id bind, so a user's score
     *     rows match their own predictions only. user_id is filtered once in WHERE.
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
