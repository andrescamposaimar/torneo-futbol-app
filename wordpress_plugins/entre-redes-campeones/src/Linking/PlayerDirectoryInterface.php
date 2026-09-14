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
     * @return RegisteredPlayer[]
     */
    public function searchByName( string $query, int $limit ): array;

    /**
     * Whether $id is a currently registered player — used by
     * LinkWriteService::setManualLink() (LINK-8) so a fat-fingered id
     * entered by an operator is rejected instead of silently creating a
     * dangling reference (item 8).
     */
    public function existsById( int $id ): bool;
}
