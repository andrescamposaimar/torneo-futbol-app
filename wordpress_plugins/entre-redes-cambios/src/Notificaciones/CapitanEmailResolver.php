<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Notificaciones;

use EntreRedes\Cambios\Observability\EventLog;

/**
 * Resolves a captain's email address from the player id this plugin stores.
 *
 * *** WHY IT READS ANOTHER PLUGIN'S TABLES ***
 * `cambios_solicitud.solicitada_por` is a PLAYER id — SportsPress has no
 * email for a player, and this plugin has never asked anybody for one. The
 * address exists because captains sign in with Google or Apple through
 * entre-redes-prode, so the chain is:
 *
 *     solicitada_por (player_id)
 *       -> prode_associations.player_id  -> user_id
 *       -> prode_users.id                -> email
 *
 * Reading prode's tables is not new here: `Auth\ProdeSessionGateway` already
 * reads `prode_users` to honour session revocation. This adds
 * `prode_associations`, and the coupling is the same one the whole feature
 * already rests on — a captain IS a prode user, that is how they
 * authenticate at all.
 *
 * *** WHY THE DELETED ROWS MATTER ***
 * Both tables are soft-deleted (`deleted_at`). An association a user removed
 * is not a way to reach them any more, and mailing a deleted account's
 * address would send a league's roster decisions to somebody who asked to be
 * forgotten. Both filters are mandatory, not an optimisation.
 *
 * *** WHY THE MOST RECENT LOGIN WINS ***
 * One player can hold more than one association — Google and Apple, with
 * different addresses, and Apple's "Hide My Email" relay among them. The
 * most recently used account is the one the captain is actually reading, so
 * ordering by `last_login_at` picks the inbox they will see rather than the
 * first one they ever created.
 *
 * *** FAILS TO null, NEVER TO A GUESS ***
 * No address means no mail, and the caller logs it. There is no fallback
 * address: guessing a recipient for somebody else's roster decisions is
 * worse than staying silent.
 */
final class CapitanEmailResolver {

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * @return string|null The address, or null when there is none to use.
     */
    public function resolve( int $playerId ): ?string {
        if ( $playerId <= 0 ) {
            return null;
        }

        $p = $this->wpdb->prefix;

        // get_row, not get_var: `get_var()` returns null for an EMPTY STRING
        // as well as for "no row" (see Calendario\SettingsWriter's docblock
        // on the same trap), and an empty email must be treated as "none"
        // deliberately rather than by accident.
        $fila = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT u.email
                   FROM {$p}prode_associations a
                   INNER JOIN {$p}prode_users u ON u.id = a.user_id
                  WHERE a.player_id = %d
                    AND a.deleted_at IS NULL
                    AND u.deleted_at IS NULL
                    AND u.email IS NOT NULL
                    AND u.email <> ''
                  ORDER BY u.last_login_at DESC, a.id DESC
                  LIMIT 1",
                $playerId
            ),
            ARRAY_A
        );

        $lastError = (string) ( $this->wpdb->last_error ?? '' );

        if ( '' !== $lastError ) {
            // A failed read is NOT "this captain has no address" — saying so
            // would make a transient DB problem indistinguishable from a
            // captain who never linked an account.
            $this->eventLog->record( 'notificacion.capitan_email_lectura_fallida', [
                'player_id'  => $playerId,
                'last_error' => $lastError,
            ] );

            return null;
        }

        if ( ! is_array( $fila ) ) {
            return null;
        }

        $email = trim( (string) ( $fila['email'] ?? '' ) );

        return '' !== $email ? $email : null;
    }
}
