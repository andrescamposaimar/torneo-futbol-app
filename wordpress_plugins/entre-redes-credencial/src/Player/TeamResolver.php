<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Player;

/**
 * Resolves the team (or waiting/non-enrolled list acting as a team, spec
 * "Credential Payload and Display") a player currently belongs to — design
 * D14. This plugin does not own that data; it only reads it through whatever
 * concrete resolver is wired in (see EntreRedesApiTeamResolver), so
 * CredencialService and its tests never depend on how the answer was
 * produced.
 *
 * A null return means "team/list name unavailable" (spec scenario): the
 * credential still renders, just without the team/list badge — never an
 * error, never a reason to withhold the rest of the payload.
 */
interface TeamResolver {

    /**
     * @return array{id: int, name: string}|null
     */
    public function resolve( int $playerId ): ?array;
}
