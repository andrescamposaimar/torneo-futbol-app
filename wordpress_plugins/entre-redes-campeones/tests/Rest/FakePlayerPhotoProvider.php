<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Rest;

use EntreRedes\Campeones\Rest\PlayerPhotoProviderInterface;

/**
 * Test double for PlayerPhotoProviderInterface. Records every call so
 * HistoryControllerTest can assert the controller batches its photo lookup
 * into exactly one call carrying the distinct set of linked ids, mirroring
 * WpPlayerDirectoryTest's playerQueryCallCount precedent.
 */
final class FakePlayerPhotoProvider implements PlayerPhotoProviderInterface {

    /** @var array<int, string|null> */
    private array $urlsById;

    /** @var array<int, int[]> Every argument this fake received, in order. */
    public array $calls = [];

    /**
     * @param array<int, string|null> $urlsById
     */
    public function __construct( array $urlsById = [] ) {
        $this->urlsById = $urlsById;
    }

    public function findPhotoUrls( array $playerIds ): array {
        $this->calls[] = $playerIds;

        $result = [];
        foreach ( $playerIds as $id ) {
            if ( array_key_exists( $id, $this->urlsById ) ) {
                $result[ $id ] = $this->urlsById[ $id ];
            }
        }

        return $result;
    }

    public function callCount(): int {
        return count( $this->calls );
    }
}
