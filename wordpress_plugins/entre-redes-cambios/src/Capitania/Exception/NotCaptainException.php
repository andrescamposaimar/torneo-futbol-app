<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania\Exception;

/**
 * The token verified and the session is current, but the `player_id` it
 * carries is not the vigent captain of the requested (season_id, team_id) —
 * either nobody captains that team, or somebody else does, or this player
 * captains a DIFFERENT team (see CapitanAuthorizerTest's "captain of A tries
 * to act on B" case, which this exception must also cover).
 */
class NotCaptainException extends AuthorizationDeniedException {
}
