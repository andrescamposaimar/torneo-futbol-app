<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth;

/**
 * The ONLY place in this plugin that touches entre-redes-prode's own table.
 * Copied from entre-redes-cambios/src/Auth/ProdeSessionGateway.php — see that
 * class's docblock for the full reasoning (schema coupling, not code
 * coupling; fails closed on a missing table or user).
 *
 * A valid RS256 signature and an unexpired `exp` are, on their own, NOT
 * enough to know a session is still alive: prode's SessionManager's
 * revocation increments `prode_users.session_version`, and a token minted
 * before a revocation keeps its stale `sv` claim forever. This class makes
 * the revocation effective from this plugin's side by comparing the token's
 * `sv` against the LIVE value.
 *
 * NOTE: this only compares `session_version`. It does not check
 * `prode_users.deleted_at` — out of scope for this slice, same cut as
 * entre-redes-cambios made.
 */
class ProdeSessionGateway {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * True only when $tokenSessionVersion still matches the LIVE
     * session_version stored for $prodeUserId. A prode user that no longer
     * exists (deleted, or never existed) returns false rather than
     * throwing — a vanished user IS a session that is no longer valid.
     */
    public function isSessionCurrent( int $prodeUserId, int $tokenSessionVersion ): bool {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $current = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT session_version FROM {$p}prode_users WHERE id = %d LIMIT 1",
                $prodeUserId
            )
        );

        if ( null === $current ) {
            return false;
        }

        return (int) $current === $tokenSessionVersion;
    }
}
