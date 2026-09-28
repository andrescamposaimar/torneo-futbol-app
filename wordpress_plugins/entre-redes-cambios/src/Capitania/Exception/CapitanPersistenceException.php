<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania\Exception;

/**
 * A wpdb write inside CapitanRepository's designation transaction failed.
 *
 * $wpdb->insert() and $wpdb->update() do NOT throw on failure — they return
 * `false` and set `$wpdb->last_error` (see wpdb's own contract, replicated
 * exactly by the SQLite test shim). Without this exception, a failed write
 * inside CapitanRepository::designateCapitan()'s transaction was silently
 * ignored: execution fell straight through to COMMIT, because nothing ever
 * threw for the existing `catch (\Throwable)` / ROLLBACK to react to. That
 * let a failed revoke still commit the new insert (two vigent captains for
 * the same team) or a failed insert still commit the revoke (the team left
 * with none) — exactly the invariant this class exists to defend.
 *
 * Thrown as soon as insert()/update() returns `false`, BEFORE the COMMIT, so
 * the transaction rolls back instead of persisting a partial write.
 */
class CapitanPersistenceException extends \RuntimeException {

    public function __construct( string $operation, ?string $wpdbLastError ) {
        parent::__construct(
            sprintf(
                'CapitanRepository failed to %s a cambios_capitan row: %s',
                $operation,
                $wpdbLastError ?? '(wpdb reported no error message)'
            )
        );
    }
}
