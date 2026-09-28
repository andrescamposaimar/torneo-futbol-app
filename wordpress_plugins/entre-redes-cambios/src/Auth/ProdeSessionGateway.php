<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth;

/**
 * The ONLY place in this plugin that touches entre-redes-prode's own table.
 *
 * WHY THIS EXISTS: a valid RS256 signature and an unexpired `exp` are, on
 * their own, NOT enough to know a session is still alive. Prode's
 * SessionManager::revokeAllSessions() invalidates every outstanding token
 * for a user by incrementing `prode_users.session_version`; the access
 * token's own `sv` claim is a SNAPSHOT of that counter taken at issuance
 * (see JwtService::issueAccessToken()). A token minted before a revocation
 * keeps its stale `sv` forever — TokenVerifier::verify() alone would accept
 * it right up to its natural `exp`, 15 minutes later, regardless of the
 * revocation. This class is what makes the revocation effective from this
 * plugin's side, by comparing the token's `sv` against the LIVE value.
 *
 * THIS IS SCHEMA COUPLING, NOT CODE COUPLING — and deliberately so. This
 * class never references a class from entre-redes-prode; it reads one
 * column of one table via a raw, prepared query, exactly the way
 * Calendario\FechaRepository only ever talks to this plugin's own tables.
 * The cost of that choice: if entre-redes-prode ever renames `prode_users`,
 * drops `session_version`, or is deactivated, this class finds out at QUERY
 * TIME — get_var() simply returns null — not at deploy time. There is no
 * migration order, dependency manifest, or autoloader failure that would
 * surface the break earlier. isSessionCurrent() treats "row not found" (a
 * missing user, or the whole table missing) as "session not current" (see
 * below), which fails CLOSED in that scenario instead of silently
 * authorizing every request.
 *
 * NOTE: this only compares `session_version`. It does not check
 * `prode_users.deleted_at` — the soft-delete column on that table is a
 * separate concern the slice task did not ask this gateway to enforce, and
 * folding it in silently would be exactly the kind of scope creep the
 * "capitan flag from sp_position" warning in CapitanRepository's sibling
 * docblocks exists to prevent. Revisit if a future slice needs a captain's
 * authorization to be revoked the instant their prode account is deleted,
 * not just when their session_version changes.
 */
class ProdeSessionGateway {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * True only when $tokenSessionVersion still matches the LIVE
     * session_version stored for $prodeUserId.
     *
     * A prode user that no longer exists (deleted, or never existed — e.g.
     * a stale/forged `sub` claim) returns false rather than throwing: a
     * vanished user IS a session that is no longer valid, and the caller's
     * required reaction (deny) is identical to any other revoked session —
     * there is nothing exceptional here for a caller to handle differently.
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
