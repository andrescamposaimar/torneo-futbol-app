<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * The ONLY WordPress-coupled file in the Linking domain (design §4). Its
 * SQL cannot run against the SQLite test shim — it has no sp_player posts,
 * no sp_season terms and no sp_current_team postmeta — so WpPlayerDirectoryTest
 * scripts a fake wpdb's get_results() calls directly instead of hitting real
 * storage. That covers the bucketing, the season join and the
 * sp_current_team = '0' sentinel mapping to null in this class's own code;
 * it does NOT confirm wp-admin actually renders that null as "Sin equipo" —
 * that remains a manual wp-admin check, still outstanding as of this slice
 * (runbook-prode-sin-equipo).
 *
 * The index is built once per request the first time it is needed — a
 * 17-year bulk import resolves hundreds of rows in one request, and
 * rebuilding the index per row would be catastrophic.
 */
final class WpPlayerDirectory implements PlayerDirectoryInterface {

    /**
     * @var array<string, RegisteredPlayer[]>|null Bucketed by key surname.
     */
    private ?array $bySurname = null;

    /**
     * @var RegisteredPlayer[]|null Flat list, including titles that key to
     *                              null (unreachable by the matcher, but
     *                              still present for search).
     */
    private ?array $flat = null;

    /**
     * @var array<int, true>|null Presence-only lookup for existsById().
     */
    private ?array $byId = null;

    public function __construct( private readonly \wpdb $wpdb ) {
    }

    public function findBySurname( string $normalizedSurname ): array {
        $this->ensureIndexBuilt();

        return $this->bySurname[ $normalizedSurname ] ?? [];
    }

    public function searchByName( string $query, int $limit ): array {
        // Signature only in this slice (design §4/§9, task 2.8) — ranking
        // and filtering land in slice 5's pure PlayerSearch class, which
        // this method will wire over the already-built flat index.
        return [];
    }

    public function existsById( int $id ): bool {
        $this->ensureIndexBuilt();

        return isset( $this->byId[ $id ] );
    }

    private function ensureIndexBuilt(): void {
        if ( null !== $this->bySurname ) {
            return;
        }

        $wpdb = $this->wpdb;

        $rows = $this->fetchOrFail(
            $wpdb->get_results(
                "SELECT ID, post_title FROM {$wpdb->posts}
                  WHERE post_type = 'sp_player' AND post_status = 'publish'",
                ARRAY_A
            ),
            'sp_player directory query'
        );

        $ids = array_map( static fn ( array $row ): int => (int) $row['ID'], $rows );

        $seasonsById     = $this->fetchSeasonsByPlayerId( $ids );
        $currentTeamById = $this->fetchCurrentTeamNamesByPlayerId( $ids );

        error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            'entre-redes-campeones: player directory index built (%d sp_player rows, %d with season data, %d with a current team).',
            count( $rows ),
            count( $seasonsById ),
            count( $currentTeamById )
        ) );

        $flat      = [];
        $bySurname = [];
        $byId      = [];

        foreach ( $rows as $row ) {
            $id    = (int) $row['ID'];
            $title = (string) $row['post_title'];
            $key   = NameParser::keyFor( $title );

            $player = new RegisteredPlayer(
                $id,
                $title,
                $seasonsById[ $id ] ?? [],
                $currentTeamById[ $id ] ?? null,
                $key
            );

            $flat[]       = $player;
            $byId[ $id ]  = true;

            if ( null !== $key ) {
                $bySurname[ $key->surname ][] = $player;
            }
        }

        $this->flat      = $flat;
        $this->bySurname = $bySurname;
        $this->byId      = $byId;
    }

    /**
     * @param int[] $ids
     * @return array<int, string[]>
     */
    private function fetchSeasonsByPlayerId( array $ids ): array {
        if ( [] === $ids ) {
            return [];
        }

        $wpdb         = $this->wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $rows = $this->fetchOrFail(
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tr.object_id AS player_id, t.name AS season_name
                       FROM {$wpdb->term_relationships} tr
                       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                       INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                      WHERE tt.taxonomy = 'sp_season' AND tr.object_id IN ({$placeholders})",
                    ...$ids
                ),
                ARRAY_A
            ),
            'player seasons query'
        );

        $seasonsById = [];
        foreach ( $rows as $row ) {
            $seasonsById[ (int) $row['player_id'] ][] = (string) $row['season_name'];
        }

        return $seasonsById;
    }

    /**
     * @param int[] $ids
     * @return array<int, string|null>
     */
    private function fetchCurrentTeamNamesByPlayerId( array $ids ): array {
        if ( [] === $ids ) {
            return [];
        }

        $wpdb         = $this->wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // sp_current_team can hold the sentinel string '0', meaning "no
        // team", not team id 0. No post has ID 0, so the LEFT JOIN yields
        // NULL for the sentinel exactly as it does for a genuinely missing
        // or unpublished team — both correctly become currentTeamName =
        // null, rendered "Sin equipo" (runbook-prode-sin-equipo).
        $rows = $this->fetchOrFail(
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.post_id AS player_id, team.post_title AS team_name
                       FROM {$wpdb->postmeta} pm
                       LEFT JOIN {$wpdb->posts} team
                         ON team.ID = CAST(pm.meta_value AS UNSIGNED)
                        AND team.post_type = 'sp_team'
                        AND team.post_status = 'publish'
                      WHERE pm.meta_key = 'sp_current_team' AND pm.post_id IN ({$placeholders})",
                    ...$ids
                ),
                ARRAY_A
            ),
            'player current team query'
        );

        $teamById = [];
        foreach ( $rows as $row ) {
            $teamById[ (int) $row['player_id'] ] = null === $row['team_name'] || '' === $row['team_name']
                ? null
                : (string) $row['team_name'];
        }

        return $teamById;
    }

    /**
     * Distinguishes a genuinely empty result from a failed query. A bare
     * `?: []` (the previous behaviour) collapsed both into the same empty
     * array — a broken `sp_player` query during a bulk import made every
     * name resolve to `sin_candidato`, indistinguishable from a legitimate
     * import of unmatchable names. wpdb::get_results() failure can surface
     * as either a falsy/null return or a populated `$wpdb->last_error` with
     * an otherwise empty array; both are checked, and either one throws.
     *
     * @param array<int, array<string, mixed>>|null|false $rows
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrFail( mixed $rows, string $context ): array {
        $wpdb   = $this->wpdb;
        $failed = ! is_array( $rows ) || '' !== (string) $wpdb->last_error;

        if ( $failed ) {
            $message = sprintf(
                'entre-redes-campeones: %s failed while building the player directory index. DB error: %s',
                $context,
                (string) $wpdb->last_error
            );
            error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            throw new PlayerDirectoryQueryException( $message );
        }

        return $rows;
    }
}
