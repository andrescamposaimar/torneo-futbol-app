<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Exception;

/**
 * A wpdb write inside PlazaRepository's transaction failed.
 *
 * $wpdb->insert() and $wpdb->update() do NOT throw on failure — they return
 * `false` and set `$wpdb->last_error` (see wpdb's own contract, replicated
 * exactly by the SQLite test shim). Thrown as soon as insert()/update()
 * returns `false`, BEFORE the COMMIT, so the transaction rolls back instead
 * of persisting a partial write — the same discipline
 * Capitania\Exception\CapitanPersistenceException enforces for
 * cambios_capitan, and for the same reason: a failed second write inside an
 * otherwise-successful transaction must not be allowed to fall through to
 * COMMIT unnoticed.
 */
class PlazaPersistenceException extends \RuntimeException {

    public function __construct( string $operation, ?string $wpdbLastError ) {
        parent::__construct(
            sprintf(
                'PlazaRepository failed to %s a row: %s',
                $operation,
                $wpdbLastError ?? '(wpdb reported no error message)'
            )
        );
    }
}
