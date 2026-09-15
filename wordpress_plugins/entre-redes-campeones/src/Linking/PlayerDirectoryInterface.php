<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * Seam over registered-player data (design §4). Implemented by
 * WpPlayerDirectory (production) and FakePlayerDirectory (tests) — the
 * RosterResolverInterface / WpRosterResolver precedent from
 * entre-redes-prode.
 */
interface PlayerDirectoryInterface {

    /**
     * @return RegisteredPlayer[]
     */
    public function findBySurname( string $normalizedSurname ): array;

    /**
     * Whether $id is a currently registered player — used by
     * LinkWriteService::setManualLink() (LINK-8) so a fat-fingered id
     * entered by an operator is rejected instead of silently creating a
     * dangling reference (item 8).
     */
    public function existsById( int $id ): bool;

    /**
     * Batched id -> RegisteredPlayer lookup, used by SquadListTable to show
     * the linked player's real name and id without one query per row.
     *
     * @param int[] $ids
     * @return array<int, RegisteredPlayer> Keyed by id. An id in $ids with
     *         no matching published sp_player is simply absent from the
     *         result — the caller must treat "requested but missing" as a
     *         dangling pointer (the player was deleted or unpublished), not
     *         a silently empty name.
     */
    public function findByIds( array $ids ): array;
}
