<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

/**
 * Encapsulates all wpdb persistence for prode_ranking_fecha_cache plus
 * read aggregations over prode_scores and prode_fechas.
 *
 * Design notes (mirrors ScoreRepository — ADR-G4-1):
 *   - Upsert uses SELECT-then-INSERT/UPDATE inside START TRANSACTION / COMMIT.
 *     INSERT … ON DUPLICATE KEY UPDATE is intentionally NOT used — the SQLite
 *     test shim cannot translate it. Code-level dedup is the authoritative
 *     mechanism (mirrors ScoreRepository::upsertScore).
 *   - All numeric fields read via get_var / get_results are cast (int) at the
 *     boundary because the SQLite shim returns strings for numeric columns.
 *   - Rank is assigned in PHP by RankingComputer, NOT via SQL window functions,
 *     which the shim cannot execute (ADR-G4-2).
 *   - display_name is resolved via a separate IN-lookup map, NOT a JOIN on the
 *     aggregation query, to keep aggregation queries shim-portable (ADR-G4-9).
 */
class RankingRepository {

    public function __construct( private \wpdb $wpdb ) {}

    // -------------------------------------------------------------------------
    // Table helper
    // -------------------------------------------------------------------------

    private function table( string $name ): string {
        return $this->wpdb->prefix . $name;
    }

    // -------------------------------------------------------------------------
    // Aggregation reads
    // -------------------------------------------------------------------------

    /**
     * Per-fecha aggregation: SUM(points) and exact_count from prode_scores.
     *
     * Returns rows shaped as { user_id: int, total_points: int, exact_count: int }.
     *
     * SQL A (design §Aggregation SQL).
     *
     * @param int $fechaId
     * @return array<int, array<string, mixed>>
     */
    public function aggregateByFecha( int $fechaId ): array {
        $sql  = $this->wpdb->prepare(
            "SELECT user_id,
                    SUM(points) AS total_points,
                    SUM(CASE WHEN evaluation_method='exact_score' THEN 1 ELSE 0 END) AS exact_count
               FROM {$this->table('prode_scores')}
              WHERE fecha_id = %d
              GROUP BY user_id",
            $fechaId
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( static function ( array $row ): array {
            return [
                'user_id'      => (int) $row['user_id'],
                'total_points' => (int) $row['total_points'],
                'exact_count'  => (int) $row['exact_count'],
            ];
        }, $rows ?: [] );
    }

    /**
     * Season-cumulative aggregation: SUM across evaluated fechas only.
     *
     * Returns rows shaped as { user_id: int, total_points: int, exact_count: int }.
     *
     * SQL B (design §Aggregation SQL).
     *
     * @param int $seasonId
     * @return array<int, array<string, mixed>>
     */
    public function aggregateBySeason( int $seasonId ): array {
        $sql  = $this->wpdb->prepare(
            "SELECT s.user_id,
                    SUM(s.points) AS total_points,
                    SUM(CASE WHEN s.evaluation_method='exact_score' THEN 1 ELSE 0 END) AS exact_count
               FROM {$this->table('prode_scores')} s
               JOIN {$this->table('prode_fechas')} f ON f.id = s.fecha_id
              WHERE f.season_id = %d AND f.state = 'evaluated'
              GROUP BY s.user_id",
            $seasonId
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( static function ( array $row ): array {
            return [
                'user_id'      => (int) $row['user_id'],
                'total_points' => (int) $row['total_points'],
                'exact_count'  => (int) $row['exact_count'],
            ];
        }, $rows ?: [] );
    }

    // -------------------------------------------------------------------------
    // Cache writes
    // -------------------------------------------------------------------------

    /**
     * Idempotent upsert into prode_ranking_fecha_cache for a batch of ranked rows.
     *
     * For each row: SELECT existing (fecha_id, user_id); INSERT if absent,
     * UPDATE if present. Wrapped in START TRANSACTION / COMMIT.
     * Dedup is code-level — shim does not enforce the UNIQUE constraint.
     *
     * @param int    $fechaId
     * @param array<int, array<string, mixed>> $rankedRows  Each must have user_id, total_points, rank.
     * @param string $computedAt  Datetime string ('Y-m-d H:i:s').
     */
    public function upsertFechaCache( int $fechaId, array $rankedRows, string $computedAt ): void {
        $wpdb = $this->wpdb;
        $wpdb->query( 'START TRANSACTION' );

        foreach ( $rankedRows as $row ) {
            $userId      = (int) $row['user_id'];
            $totalPoints = (int) $row['total_points'];
            $rank        = (int) $row['rank'];

            $existingId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table('prode_ranking_fecha_cache')}
                      WHERE fecha_id = %d AND user_id = %d LIMIT 1",
                    $fechaId,
                    $userId
                )
            );

            if ( null === $existingId ) {
                $wpdb->insert(
                    $this->table( 'prode_ranking_fecha_cache' ),
                    [
                        'fecha_id'     => $fechaId,
                        'user_id'      => $userId,
                        'total_points' => $totalPoints,
                        'rank'         => $rank,
                        'computed_at'  => $computedAt,
                    ]
                );
            } else {
                $wpdb->update(
                    $this->table( 'prode_ranking_fecha_cache' ),
                    [
                        'total_points' => $totalPoints,
                        'rank'         => $rank,
                        'computed_at'  => $computedAt,
                    ],
                    [ 'id' => (int) $existingId ]
                );
            }
        }

        $wpdb->query( 'COMMIT' );
    }

    // -------------------------------------------------------------------------
    // Cache reads
    // -------------------------------------------------------------------------

    /**
     * Return all cached rows for a fecha, ordered by rank ASC, user_id ASC.
     *
     * Pagination is NOT done here — the full set is returned; callers slice
     * in PHP (mirrors FechaController shaping; ADR-G4-6).
     *
     * Numeric fields are cast (int) at the boundary.
     *
     * SQL E (design §Aggregation SQL).
     *
     * @param int $fechaId
     * @return array<int, array<string, mixed>>
     */
    public function findFechaCache( int $fechaId ): array {
        $sql  = $this->wpdb->prepare(
            "SELECT user_id, total_points, rank
               FROM {$this->table('prode_ranking_fecha_cache')}
              WHERE fecha_id = %d
              ORDER BY rank ASC, user_id ASC",
            $fechaId
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( static function ( array $row ): array {
            return [
                'user_id'      => (int) $row['user_id'],
                'total_points' => (int) $row['total_points'],
                'rank'         => (int) $row['rank'],
            ];
        }, $rows ?: [] );
    }

    /**
     * Count total cached rows for a fecha_id.
     *
     * @param int $fechaId
     * @return int
     */
    public function countFechaCache( int $fechaId ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table('prode_ranking_fecha_cache')}
                  WHERE fecha_id = %d",
                $fechaId
            )
        );
    }

    // -------------------------------------------------------------------------
    // Display name lookup
    // -------------------------------------------------------------------------

    /**
     * Resolve display names for a list of user IDs.
     *
     * Returns a map of user_id → display_name via a single IN(...) query.
     * Separate lookup (not JOIN) to keep aggregation queries shim-portable
     * and avoid N+1 ambiguity (ADR-G4-9).
     *
     * Returns empty array when $userIds is empty.
     *
     * SQL C (design §Aggregation SQL).
     *
     * @param array<int> $userIds
     * @return array<int, string>  keyed by user_id
     */
    public function resolveDisplayNames( array $userIds ): array {
        if ( empty( $userIds ) ) {
            return [];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $userIds ), '%d' ) );
        $sql          = $this->wpdb->prepare(
            "SELECT id, display_name FROM {$this->table('prode_users')}
              WHERE id IN ({$placeholders})",
            $userIds
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        $map = [];
        foreach ( $rows ?: [] as $row ) {
            $map[ (int) $row['id'] ] = (string) $row['display_name'];
        }
        return $map;
    }

    // -------------------------------------------------------------------------
    // Fecha discovery
    // -------------------------------------------------------------------------

    /**
     * Return IDs of all evaluated fechas for a given tenant.
     *
     * Used by RankingCron to determine which fechas to process each run.
     *
     * SQL D (design §Aggregation SQL).
     *
     * @param string $tenantId
     * @return array<int>
     */
    public function listEvaluatedFechaIds( string $tenantId ): array {
        $sql  = $this->wpdb->prepare(
            "SELECT id FROM {$this->table('prode_fechas')}
              WHERE tenant_id = %s AND state = 'evaluated'",
            $tenantId
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( static fn( array $row ): int => (int) $row['id'], $rows ?: [] );
    }
}
