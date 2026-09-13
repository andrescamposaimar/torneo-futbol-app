<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

use EntreRedes\Campeones\Linking\PlayerDirectoryInterface;
use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;

/**
 * A PlayerDirectoryInterface double whose findBySurname() always throws
 * PlayerDirectoryQueryException, exactly as WpPlayerDirectory does on a
 * failed sp_player/sp_season/sp_current_team read. Used to prove the admin
 * boundary (TitlesPage / TitleEditorPage) catches it instead of letting it
 * surface as an unhandled fatal mid-write (item 7).
 */
final class ThrowingPlayerDirectory implements PlayerDirectoryInterface {

    public function findBySurname( string $normalizedSurname ): array {
        throw new PlayerDirectoryQueryException( 'Simulated player directory query failure for test' );
    }

    public function searchByName( string $query, int $limit ): array {
        return [];
    }
}
