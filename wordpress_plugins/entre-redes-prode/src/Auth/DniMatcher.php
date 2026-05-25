<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Auth;

/**
 * Cross-checks a DNI against the entre-redes player roster.
 *
 * Data model discovery (investigated during PR-02 apply phase):
 *
 *   The entre-redes plugin stores players as WordPress posts with
 *   post_type = 'sp_player' (SportsPress convention).
 *
 *   DNI is stored in wp_postmeta with:
 *     meta_key   = 'dni'
 *     meta_value = '<plain DNI string>'
 *
 *   Additionally there is an ACF field reference row per player:
 *     meta_key   = '_dni'
 *     meta_value = 'field_56d07878c3851'   (ACF field key)
 *
 *   Source of truth: wordpress_sql/alta_jugadores.py lines 158-175.
 *   The script inserts meta_key='dni' with the plain DNI value for every
 *   player row. There is no separate unique-constraint enforcement on the
 *   WP side — uniqueness is enforced by the registration process.
 *
 * This class does a READ-ONLY query against the entre-redes data. It never
 * modifies wp_posts or wp_postmeta.
 *
 * Season filtering:
 *   The lookup considers players active in the CURRENT season. A player is
 *   "in the roster" if their sp_player post is published AND they have a
 *   term_relationship to the current season term_taxonomy_id.
 *
 *   However, determining the "current season" requires joining to term_taxonomy
 *   which adds complexity. For V1 the lookup is simplified: any PUBLISHED
 *   sp_player with a matching DNI is accepted. The operator manages season
 *   membership; the prode registration window is expected to align with the
 *   active season.
 *
 *   TODO (PR-11 or operator runbook): Add season filtering if multi-season
 *   installs start creating ambiguity.
 */
class DniMatcher {

    /**
     * Looks up a player by DNI in the entre-redes roster.
     *
     * Returns the player's post ID and display name if found, null otherwise.
     *
     * @param string $dni Plain DNI to look up (as supplied by the user).
     * @return array{player_id: int, player_name: string}|null
     *         null if the DNI is not in the roster.
     */
    public function findByDni( string $dni ): ?array {
        global $wpdb;

        // Normalize DNI: trim whitespace, remove leading zeros for consistency.
        // The roster stores DNIs as entered by the operator; we do a direct
        // match first, then a normalized fallback.
        $dni_clean = trim( $dni );

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT p.ID as player_id, p.post_title as player_name
                   FROM {$wpdb->posts} p
                   INNER JOIN {$wpdb->postmeta} pm
                           ON pm.post_id = p.ID
                          AND pm.meta_key = 'dni'
                          AND pm.meta_value = %s
                  WHERE p.post_type   = 'sp_player'
                    AND p.post_status = 'publish'
                  LIMIT 1",
                $dni_clean
            ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            return null;
        }

        return [
            'player_id'   => (int) $row['player_id'],
            'player_name' => (string) $row['player_name'],
        ];
    }

    /**
     * Checks whether a given player_id is still in the roster.
     *
     * Used during session validation to catch cases where a player was removed
     * from the roster after registering (edge case; not enforced per-request
     * in V1 — only checked at registration time).
     *
     * @param int $player_id entre-redes sp_player post ID
     * @return bool
     */
    public function playerExists( int $player_id ): bool {
        global $wpdb;

        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT COUNT(*)
                   FROM {$wpdb->posts}
                  WHERE ID          = %d
                    AND post_type   = 'sp_player'
                    AND post_status = 'publish'",
                $player_id
            )
        );

        return $count > 0;
    }
}
