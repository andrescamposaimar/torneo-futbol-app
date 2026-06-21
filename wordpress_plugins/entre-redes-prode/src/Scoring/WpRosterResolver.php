<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

/**
 * Production roster resolver: resolves avatar_url and team_name for a batch
 * of Prode user IDs using the WordPress/SportsPress layer.
 *
 * Data flow:
 *   1. RankingRepository::resolvePlayerIds() — single IN-query on
 *      prode_associations WHERE deleted_at IS NULL → user_id → player_id map.
 *   2. For each player_id:
 *      - Photo: get_the_post_thumbnail_url( $playerId, 'thumbnail' ) — returns
 *        the featured-image URL of the sp_player post, or false/'' when none.
 *      - Team: get_post_meta( $playerId, 'sp_current_team', true ) returns the
 *        current club's sp_team post ID (single value); resolve its title via
 *        get_the_title(). Falls back to null when missing or '0' (unassigned).
 *
 * Meta key (verified against this install's seed/association scripts in
 * wordpress_sql/): the current club is 'sp_current_team' = <sp_team post ID>.
 * The DB prefix is resolved dynamically by $wpdb (production uses 'wp_').
 */
class WpRosterResolver implements RosterResolverInterface {

    /**
     * SportsPress post-meta key for a player's CURRENT team assignment.
     *
     * Verified against this install's data (wordpress_sql/asociar_jugadores_clubes_temporada_2026.sql):
     * the active club is stored as a single wp_postmeta row with
     * meta_key = 'sp_current_team', meta_value = <sp_team post ID>. The
     * 'sp_team' key holds the (possibly multi-row) team history; we want the
     * current club, so we read 'sp_current_team'. Players with no club carry
     * no row (or a legacy '0') → resolves to null → "Sin Equipo". The import
     * scripts no longer seed '0' and the association script deletes any '0'
     * sentinel, so a stale row can never win over the real club ID.
     */
    private const SP_TEAM_META_KEY = 'sp_current_team';

    public function __construct( private RankingRepository $repo ) {}

    /**
     * @param array<int> $userIds
     * @return array<int, array{avatar_url: ?string, team_name: ?string}>
     */
    public function resolve( array $userIds ): array {
        if ( empty( $userIds ) ) {
            return [];
        }

        // Step 1: batch-resolve user_id → player_id (single IN-query).
        $playerIds = $this->repo->resolvePlayerIds( $userIds );

        $result = [];
        foreach ( $playerIds as $userId => $playerId ) {
            $result[ $userId ] = [
                'avatar_url' => $this->resolveAvatar( $playerId ),
                'team_name'  => $this->resolveTeam( $playerId ),
            ];
        }

        return $result;
    }

    /**
     * Resolve the featured-image URL for a sp_player post.
     *
     * @return string|null  URL string, or null when none.
     */
    private function resolveAvatar( int $playerId ): ?string {
        $url = get_the_post_thumbnail_url( $playerId, 'thumbnail' );
        if ( ! is_string( $url ) || '' === $url ) {
            return null;
        }
        return $url;
    }

    /**
     * Resolve the team name for a sp_player post via sp_team post-meta.
     *
     * @return string|null  Team name, or null when none.
     */
    private function resolveTeam( int $playerId ): ?string {
        // sp_current_team is a single-value meta: the current club's post ID.
        $teamId = (int) get_post_meta( $playerId, self::SP_TEAM_META_KEY, true );
        if ( $teamId <= 0 ) {
            return null;
        }
        $name = get_the_title( $teamId );
        return ( is_string( $name ) && '' !== $name ) ? $name : null;
    }
}
