<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * The ONLY WordPress-coupled file in the Linking domain (design §4). Not
 * shim-testable — the SQLite test shim has no sp_player posts, no
 * sp_season terms and no sp_current_team postmeta. Verified manually in
 * wp-admin during slice 3 (task 2.12): confirm the sp_current_team = '0'
 * sentinel renders "Sin equipo", not a broken team link
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

    private function ensureIndexBuilt(): void {
        if ( null !== $this->bySurname ) {
            return;
        }

        $wpdb = $this->wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
              WHERE post_type = 'sp_player' AND post_status = 'publish'",
            ARRAY_A
        ) ?: [];

        $ids = array_map( static fn ( array $row ): int => (int) $row['ID'], $rows );

        $seasonsById     = $this->fetchSeasonsByPlayerId( $ids );
        $currentTeamById = $this->fetchCurrentTeamNamesByPlayerId( $ids );

        $flat      = [];
        $bySurname = [];

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

            $flat[] = $player;

            if ( null !== $key ) {
                $bySurname[ $key->surname ][] = $player;
            }
        }

        $this->flat      = $flat;
        $this->bySurname = $bySurname;
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

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.object_id AS player_id, t.name AS season_name
                   FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                  WHERE tt.taxonomy = 'sp_season' AND tr.object_id IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];

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
        $rows = $wpdb->get_results(
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
        ) ?: [];

        $teamById = [];
        foreach ( $rows as $row ) {
            $teamById[ (int) $row['player_id'] ] = null === $row['team_name'] || '' === $row['team_name']
                ? null
                : (string) $row['team_name'];
        }

        return $teamById;
    }
}
