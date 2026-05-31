<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * Encapsulates all wpdb persistence for prode_fechas + prode_fecha_matches.
 *
 * Idempotency strategy (ADR-G0-3):
 *   Before inserting a new prode_fechas row, check whether a non-evaluated
 *   (state IN ('open','locked')) fecha for the same (tenant_id, season_id)
 *   already exists whose earliest match_kickoff date equals the incoming
 *   play-date. If found, reuse that fecha_id.
 *
 *   For match rows, a SELECT-then-insert guard deduplicates each row in code.
 *   The uq_fecha_match UNIQUE KEY is dropped by the SQLite test shim, so we
 *   cannot rely on INSERT IGNORE for correctness in tests — the code guard is
 *   the authoritative dedup mechanism (INSERT IGNORE is also used as a belt,
 *   but tests prove idempotency via row count assertions, not DB constraint).
 *
 * Team names (home_team / away_team) are NOT persisted — ADR-G0-2 / ADR-P008.
 * The schema has no such columns; this class intentionally omits them.
 */
class FechaRepository {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Idempotent upsert: create or reuse the fecha for the given play-date.
     *
     * Returns the fecha_id (existing or newly inserted).
     *
     * @param string  $tenantId  Tenant identifier (PRODE_TENANT_ID).
     * @param int     $seasonId  Season ID from prode_settings.
     * @param string  $lockedAt  Computed locked_at datetime ('Y-m-d H:i:s').
     * @param array<int, array{match_id: int, kickoff: string, ...}> $matches
     * @return int fecha_id
     */
    public function upsertFecha( string $tenantId, int $seasonId, string $lockedAt, array $matches ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        // Derive the play-date from the earliest match kickoff in the incoming set.
        $kickoffs = array_column( $matches, 'kickoff' );
        $playDate = substr( min( $kickoffs ), 0, 10 ); // 'Y-m-d'

        // Step 1: look for an existing non-evaluated fecha with the same play-date.
        $existingId = $this->findExistingFechaId( $tenantId, $seasonId, $playDate );

        $wpdb->query( 'START TRANSACTION' );

        if ( null !== $existingId ) {
            $fechaId = $existingId;
        } else {
            // Step 2: insert a new prode_fechas row.
            $wpdb->insert(
                $p . 'prode_fechas',
                [
                    'tenant_id'  => $tenantId,
                    'season_id'  => $seasonId,
                    'locked_at'  => $lockedAt,
                    'state'      => 'open',
                    'created_at' => current_time( 'mysql' ),
                ]
            );
            $fechaId = $wpdb->insert_id;
        }

        // Step 3: insert match rows (SELECT-then-insert dedup — ADR-G0-3).
        foreach ( $matches as $match ) {
            $matchId  = (int) $match['match_id'];
            $kickoff  = $match['kickoff'];

            // Check if this (fecha_id, match_id) pair already exists.
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$p}prode_fecha_matches
                      WHERE fecha_id = %d AND match_id = %d
                      LIMIT 1",
                    $fechaId,
                    $matchId
                )
            );

            if ( null === $exists ) {
                // INSERT OR IGNORE as a belt — code guard above is the primary dedup.
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$p}prode_fecha_matches (fecha_id, match_id, match_kickoff)
                         VALUES (%d, %d, %s)",
                        $fechaId,
                        $matchId,
                        $kickoff
                    )
                );
            }
        }

        $wpdb->query( 'COMMIT' );

        return $fechaId;
    }

    /**
     * Return the most relevant active (open/locked) fecha for the given
     * tenant+season, or null when none exists.
     *
     * Returns the fecha nearest to locking (earliest locked_at), tie-broken
     * by most-recently created (created_at DESC).
     *
     * @return array{fecha: array<string, mixed>, matches: array<int, array<string, mixed>>}|null
     */
    public function findActiveFecha( string $tenantId, int $seasonId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $fecha = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fechas
                  WHERE tenant_id = %s
                    AND season_id = %d
                    AND state IN ('open', 'locked')
                  ORDER BY locked_at ASC, created_at DESC
                  LIMIT 1",
                $tenantId,
                $seasonId
            ),
            ARRAY_A
        );

        if ( empty( $fecha ) ) {
            return null;
        }

        $fechaId = (int) $fecha['id'];

        $matchRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fecha_matches
                  WHERE fecha_id = %d",
                $fechaId
            ),
            ARRAY_A
        );

        return [
            'fecha'   => $fecha,
            'matches' => $matchRows,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Look for a non-evaluated fecha whose matches have MIN(match_kickoff) date
     * equal to the given play_date for the (tenant_id, season_id) pair.
     *
     * Returns the fecha id or null.
     */
    private function findExistingFechaId( string $tenantId, int $seasonId, string $playDate ): ?int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT f.id
                   FROM {$p}prode_fechas f
                   INNER JOIN {$p}prode_fecha_matches fm ON fm.fecha_id = f.id
                  WHERE f.tenant_id = %s
                    AND f.season_id = %d
                    AND f.state IN ('open', 'locked')
                  GROUP BY f.id
                 HAVING MIN(DATE(fm.match_kickoff)) = %s
                  LIMIT 1",
                $tenantId,
                $seasonId,
                $playDate
            )
        );

        return null !== $id ? (int) $id : null;
    }
}
