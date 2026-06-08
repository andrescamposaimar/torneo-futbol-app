<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Repairs prode_users.display_name rows that were stored as the SSO email
 * instead of the real player name.
 *
 * Why this exists:
 *   Sign in with Apple only delivers the user's name on the FIRST authorization
 *   (and never inside the identity-token JWT on later sign-ins). Before the
 *   client started forwarding the native credential's name, those users were
 *   persisted with display_name = their email — typically the opaque
 *   `xxx@privaterelay.appleid.com` relay address. Apple never re-sends the name,
 *   so the app cannot self-heal; we backfill from the tournament roster instead.
 *
 * The real name is the source of truth in the roster (an `sp_player` post), and
 * every Prode user is linked to one via the active association's player_id. The
 * player-name lookup is constructor-injected (mirroring BackfillMatchMetaService)
 * so this service is unit-testable against just prode_users + prode_associations
 * without a real wp_posts table.
 *
 * Idempotent: re-running only touches rows that still match the broken-name
 * criteria, and a player whose roster name is empty is left untouched (we never
 * overwrite a name with emptiness).
 */
class RepairDisplayNamesService {

    private \wpdb $wpdb;

    /** @var callable(int):?string fn(int $playerId): ?string realName */
    private $playerNameByIdFn;

    /**
     * @param \wpdb    $wpdb
     * @param callable $playerNameByIdFn fn(int $playerId): ?string — returns the
     *                                    roster's full name for a player id, or
     *                                    null when not found / not published.
     */
    public function __construct( \wpdb $wpdb, callable $playerNameByIdFn ) {
        $this->wpdb             = $wpdb;
        $this->playerNameByIdFn = $playerNameByIdFn;
    }

    /**
     * Repairs every broken display_name it can resolve.
     *
     * "Broken" (criterio amplio) means the display_name is the Apple relay
     * address, equals the stored email, or is empty.
     *
     * @return array{scanned:int, repaired:int} scanned = candidate rows found;
     *         repaired = rows whose display_name was actually overwritten.
     */
    public function run(): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        // Candidates: active users with an active association whose name looks
        // like the SSO email (relay or otherwise) or is empty. Joined to the
        // association to recover the player_id we resolve the real name from.
        $rows = $wpdb->get_results(
            "SELECT u.id AS user_id, u.display_name, a.player_id
               FROM {$p}prode_users u
         INNER JOIN {$p}prode_associations a
                 ON a.user_id = u.id AND a.deleted_at IS NULL
              WHERE u.deleted_at IS NULL
                AND (
                    u.display_name LIKE '%@privaterelay.appleid.com'
                    OR u.display_name = u.email
                    OR u.display_name = ''
                )",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ 'scanned' => 0, 'repaired' => 0 ];
        }

        $scanned  = 0;
        $repaired = 0;

        foreach ( $rows as $row ) {
            $scanned++;

            $playerId = (int) $row['player_id'];
            $realName = ( $this->playerNameByIdFn )( $playerId );
            $realName = is_string( $realName ) ? trim( $realName ) : '';

            if ( '' === $realName ) {
                continue; // No usable roster name — never overwrite with emptiness.
            }

            if ( $realName === (string) $row['display_name'] ) {
                continue; // Already correct (defensive — criteria shouldn't match this).
            }

            $result = $wpdb->update(
                $p . 'prode_users',
                [ 'display_name' => $realName ],
                [ 'id' => (int) $row['user_id'] ]
            );

            if ( false !== $result ) {
                $repaired++;
            }
        }

        return [ 'scanned' => $scanned, 'repaired' => $repaired ];
    }
}
