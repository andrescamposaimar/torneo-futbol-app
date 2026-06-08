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
 * Team names, zona and escudos ARE persisted as a per-match snapshot since
 * v0.5.2 (supersedes the original ADR-G0-2 / ADR-P008 "resolve live at read
 * time" decision). The live endpoint drops matches once they are played, which
 * left locked/evaluated fechas with empty team names; snapshotting at seed time
 * makes a played fecha immutable and self-contained.
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
        // Team names, zona and escudos are snapshotted at seed time (v0.5.2) so a
        // played fecha stays self-contained — see InitialSchema::sqlProdeFechaMatches.
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
                $homeTeam   = (string) ( $match['home_team'] ?? '' );
                $awayTeam   = (string) ( $match['away_team'] ?? '' );
                $zona       = (string) ( $match['zona'] ?? '' );
                $homeEscudo = isset( $match['home_escudo'] ) && '' !== $match['home_escudo'] ? (string) $match['home_escudo'] : null;
                $awayEscudo = isset( $match['away_escudo'] ) && '' !== $match['away_escudo'] ? (string) $match['away_escudo'] : null;

                // INSERT OR IGNORE as a belt — code guard above is the primary dedup.
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$p}prode_fecha_matches
                         (fecha_id, match_id, match_kickoff, home_team, away_team, zona, home_escudo, away_escudo)
                         VALUES (%d, %d, %s, %s, %s, %s, %s, %s)",
                        $fechaId,
                        $matchId,
                        $kickoff,
                        $homeTeam,
                        $awayTeam,
                        $zona,
                        $homeEscudo,
                        $awayEscudo
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

    /**
     * Return the IDs of every fecha that is DUE for evaluation: not yet
     * 'evaluated' and whose derived lock time has passed (now >= locked_at).
     *
     * 'locked' is never persisted (mirrors LockComputer::deriveState), so the
     * due set is selected by locked_at, not by the state column. Ordered by
     * locked_at ASC, created_at DESC so the evaluator processes the oldest-due
     * fecha first.
     *
     * EvaluatorCron evaluates EVERY returned id (not just the first), so a
     * permanently-pending fecha — e.g. one with a match that never gets a final
     * result — cannot starve evaluation of newer, complete fechas.
     *
     * @param string $tenantId
     * @param string $now  Current datetime ('Y-m-d H:i:s'), e.g. current_time('mysql').
     * @return array<int>
     */
    public function listDueFechaIds( string $tenantId, string $now ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$p}prode_fechas
                  WHERE tenant_id = %s AND state != 'evaluated' AND locked_at <= %s
                  ORDER BY locked_at ASC, created_at DESC",
                $tenantId,
                $now
            ),
            ARRAY_A
        );

        return array_map( static fn( array $r ): int => (int) $r['id'], $rows ?: [] );
    }

    /**
     * Return all fechas for a season, ordered by locked_at ASC, with derived
     * state and match_count. Suitable for the "Fecha N" selector UI.
     *
     * Each item:
     *   { fecha_id: int, season_id: int, state: string, locked_at: string, match_count: int }
     *
     * The optional $lockComputer is used to derive the effective state; when
     * null the persisted state column value is returned as-is.
     *
     * @param string            $tenantId
     * @param int               $seasonId
     * @param LockComputer|null $lockComputer
     * @return array<int, array{fecha_id: int, season_id: int, state: string, locked_at: string, match_count: int}>
     */
    public function listFechasBySeasonId( string $tenantId, int $seasonId, ?LockComputer $lockComputer = null ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $fechas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fechas
                  WHERE tenant_id = %s
                    AND season_id = %d
                  ORDER BY locked_at ASC",
                $tenantId,
                $seasonId
            ),
            ARRAY_A
        );

        if ( empty( $fechas ) ) {
            return [];
        }

        // Collect match counts in one query (shim-safe: simple COUNT + GROUP BY).
        $fechaIds    = array_column( $fechas, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $fechaIds ), '%d' ) );
        $matchCounts = [];

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $countRows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT fecha_id, COUNT(*) AS match_count
                   FROM {$p}prode_fecha_matches
                  WHERE fecha_id IN ({$placeholders})
                  GROUP BY fecha_id",
                ...$fechaIds
            ),
            ARRAY_A
        );

        foreach ( $countRows as $countRow ) {
            $matchCounts[ (int) $countRow['fecha_id'] ] = (int) $countRow['match_count'];
        }

        $now = current_time( 'mysql' );

        $list = [];
        foreach ( $fechas as $fecha ) {
            $fechaId       = (int) $fecha['id'];
            $persistedState = (string) $fecha['state'];
            $lockedAt       = (string) $fecha['locked_at'];

            $state = null !== $lockComputer
                ? $lockComputer->deriveState( $lockedAt, $persistedState, $now )
                : $persistedState;

            $list[] = [
                'fecha_id'    => $fechaId,
                'season_id'   => (int) $fecha['season_id'],
                'state'       => $state,
                'locked_at'   => $lockedAt,
                'match_count' => $matchCounts[ $fechaId ] ?? 0,
            ];
        }

        return $list;
    }

    /**
     * Fetch one fecha (ANY state) by id for the given tenant.
     *
     * Returns the SAME structure as findActiveFecha (fecha + matches array) so
     * it can be enriched and shaped identically by the controller. Returns null
     * when the fecha does not exist or belongs to a different tenant.
     *
     * @return array{fecha: array<string, mixed>, matches: array<int, array<string, mixed>>}|null
     */
    public function findFechaById( string $tenantId, int $fechaId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $fecha = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fechas
                  WHERE id = %d
                    AND tenant_id = %s
                  LIMIT 1",
                $fechaId,
                $tenantId
            ),
            ARRAY_A
        );

        if ( empty( $fecha ) ) {
            return null;
        }

        $matchRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fecha_matches
                  WHERE fecha_id = %d",
                (int) $fecha['id']
            ),
            ARRAY_A
        );

        return [
            'fecha'   => $fecha,
            'matches' => $matchRows,
        ];
    }

    /**
     * Return the MAX season_id present across all fechas for the given tenant.
     * Returns null when no fechas exist.
     */
    public function findMaxSeasonId( string $tenantId ): ?int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(season_id) FROM {$p}prode_fechas WHERE tenant_id = %s",
                $tenantId
            )
        );

        return null !== $value ? (int) $value : null;
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
