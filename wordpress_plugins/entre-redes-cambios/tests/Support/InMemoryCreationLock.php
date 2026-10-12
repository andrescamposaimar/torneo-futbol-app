<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Support;

use EntreRedes\Cambios\Solicitudes\Exception\CreationLockUnavailableException;
use EntreRedes\Cambios\Solicitudes\Lock\CreationLock;

/**
 * Test double for `CreationLock` — see that interface's docblock for why the
 * property, not the MySQL mechanism, is what tests assert. `isHeld()` lets a
 * spy inside the critical section (a wpdb that records every read/insert)
 * prove the section ran WHILE the lock was held; `$eventos` records the
 * acquire/release order and `$seasons` which season was locked.
 */
final class InMemoryCreationLock implements CreationLock {

    private bool $held = false;

    /** @var string[] */
    public array $eventos = [];

    /** @var int[] */
    public array $seasons = [];

    public function __construct( private bool $disponible = true ) {
    }

    public function isHeld(): bool {
        return $this->held;
    }

    public function run( int $seasonId, callable $criticalSection ): mixed {
        if ( ! $this->disponible ) {
            $this->eventos[] = 'unavailable';

            throw new CreationLockUnavailableException( $seasonId );
        }

        $this->held      = true;
        $this->eventos[] = 'acquire';
        $this->seasons[] = $seasonId;

        try {
            return $criticalSection();
        } finally {
            $this->held      = false;
            $this->eventos[] = 'release';
        }
    }
}
