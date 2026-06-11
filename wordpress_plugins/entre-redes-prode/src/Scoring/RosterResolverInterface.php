<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

/**
 * Resolves avatar_url and team_name for a batch of Prode user IDs.
 *
 * Implementations are injectable so tests can supply canned data without
 * requiring WordPress/SportsPress functions (get_the_post_thumbnail_url,
 * get_post_meta, get_the_title).
 *
 * Production implementation: WpRosterResolver.
 */
interface RosterResolverInterface {

    /**
     * Resolve photo and team for a list of user IDs.
     *
     * Returns a map of user_id → ['avatar_url' => ?string, 'team_name' => ?string].
     * Users with no association are absent from the map; callers default both
     * fields to null when a user_id is not present.
     *
     * @param array<int> $userIds
     * @return array<int, array{avatar_url: ?string, team_name: ?string}>
     */
    public function resolve( array $userIds ): array;
}
