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
 *      - Team: get_post_meta( $playerId, 'sp_team', false ) returns an array of
 *        sp_team post IDs (SportsPress stores multi-value meta as repeating rows);
 *        take the first entry and resolve its title via get_the_title(). Falls
 *        back to '' when no meta or empty array.
 *
 * sp_team meta key notes (verified against SportsPress data model):
 *   SportsPress stores a player's squad assignment as wp_postmeta rows with
 *   meta_key = 'sp_team'. When a player belongs to one team this is a single
 *   row; when they are on multiple rosters there are multiple rows. Using
 *   get_post_meta( $id, 'sp_team', false ) (fourth arg = false → return all
 *   values as array) reliably handles both cases. We take element [0].
 *
 *   If 'sp_team' returns empty and a production verification reveals a different
 *   key (e.g. 'sp_current_team'), swap the meta key constant here — nothing
 *   else needs to change.
 */
class WpRosterResolver implements RosterResolverInterface {

    /** SportsPress post-meta key for a player's team assignment. */
    private const SP_TEAM_META_KEY = 'sp_team';

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
        $teams = get_post_meta( $playerId, self::SP_TEAM_META_KEY, false );
        if ( ! is_array( $teams ) || empty( $teams ) ) {
            return null;
        }
        $teamId = (int) $teams[0];
        if ( $teamId <= 0 ) {
            return null;
        }
        $name = get_the_title( $teamId );
        return ( is_string( $name ) && '' !== $name ) ? $name : null;
    }
}
