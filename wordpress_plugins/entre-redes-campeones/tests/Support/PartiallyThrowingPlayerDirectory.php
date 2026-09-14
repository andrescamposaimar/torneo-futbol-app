<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

use EntreRedes\Campeones\Linking\PlayerDirectoryInterface;
use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;

/**
 * A PlayerDirectoryInterface decorator that throws PlayerDirectoryQueryException
 * ONLY for one or more chosen normalized surnames, delegating every other
 * lookup to a real directory double. ThrowingPlayerDirectory throws for
 * every call, which cannot prove a mid-loop failure leaves the REST of a
 * RevalidationService::revalidateYear() pass intact (item 6) — this double
 * can mix one or more broken rows with one or more healthy ones in the
 * same run.
 *
 * Accepts a single surname or an array of surnames (item 7, round 2): a
 * single-surname double can only ever prove ONE failing row is handled
 * correctly — RevalidationResult::$directoryErrorRowIds accumulating
 * across TWO OR MORE failing rows in the same pass, while the rest of the
 * year still processes, needs more than one surname to throw for.
 */
final class PartiallyThrowingPlayerDirectory implements PlayerDirectoryInterface {

    /** @var string[] */
    private readonly array $throwForSurnames;

    /**
     * @param string|string[] $throwForSurnames
     */
    public function __construct(
        private readonly PlayerDirectoryInterface $delegate,
        string|array $throwForSurnames
    ) {
        $this->throwForSurnames = is_array( $throwForSurnames ) ? $throwForSurnames : [ $throwForSurnames ];
    }

    public function findBySurname( string $normalizedSurname ): array {
        if ( in_array( $normalizedSurname, $this->throwForSurnames, true ) ) {
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
