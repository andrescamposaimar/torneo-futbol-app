<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

use EntreRedes\Campeones\Linking\PlayerDirectoryInterface;
use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;

/**
 * A PlayerDirectoryInterface decorator that throws PlayerDirectoryQueryException
 * ONLY for one chosen normalized surname, delegating every other lookup to a
 * real directory double. ThrowingPlayerDirectory throws for every call,
 * which cannot prove a mid-loop failure leaves the REST of a
 * RevalidationService::revalidateYear() pass intact (item 6) — this double
 * can mix one broken row with one healthy one in the same run.
 */
final class PartiallyThrowingPlayerDirectory implements PlayerDirectoryInterface {

    public function __construct(
        private readonly PlayerDirectoryInterface $delegate,
        private readonly string $throwForSurname
    ) {
    }

    public function findBySurname( string $normalizedSurname ): array {
        if ( $normalizedSurname === $this->throwForSurname ) {
            throw new PlayerDirectoryQueryException( 'Simulated player directory query failure for test' );
        }

        return $this->delegate->findBySurname( $normalizedSurname );
    }

    public function searchByName( string $query, int $limit ): array {
        return $this->delegate->searchByName( $query, $limit );
    }

    public function existsById( int $id ): bool {
        return $this->delegate->existsById( $id );
    }
}
