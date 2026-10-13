<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Lock;

use EntreRedes\Cambios\Solicitudes\Exception\CreationLockUnavailableException;

/**
 * Serializes the check-then-insert of solicitud creation (0.1.21).
 *
 * THE RACE THIS CLOSES: `Reglas\PlazaSinSolicitudAbierta` and
 * `Reglas\EntranteSinSolicitudAbierta` read "is anything open?" and only
 * afterwards does `SolicitudRepository` insert. Two concurrent creates can
 * both read "nothing open" and both insert, producing the exact duplicate
 * state the rules exist to prevent — after which Friday's `publicarLote()`
 * aborts for EVERY team. No UNIQUE constraint can express "at most one open
 * request per plaza OR per entrante across two columns", so the read and the
 * insert are made one critical section instead.
 *
 * `run()` holds the lock for the whole of $criticalSection — the dictamen
 * evaluation AND the insert — and releases it on EVERY exit path: normal
 * return, refusal (an exception the section throws) and any other exception.
 * A leaked lock would freeze creation for the whole season.
 *
 * It is an INTERFACE because the production mechanism (`MysqlCreationLock`,
 * MySQL `GET_LOCK`) does not exist in the SQLite test shim. Tests substitute
 * `Tests\Support\InMemoryCreationLock` and assert the PROPERTY — the critical
 * section runs while the lock is held, and it is released on each exit path
 * — never the mechanism. (This plugin has a documented incident of a test
 * asserting a UNIQUE constraint the shim had silently dropped: it proved
 * nothing. Do not "test" `GET_LOCK` against SQLite.)
 */
interface CreationLock {

    /**
     * @template T
     * @param callable(): T $criticalSection
     * @return T Whatever $criticalSection returns.
     * @throws CreationLockUnavailableException When the lock is not acquired
     *         within the bounded wait; $criticalSection never runs.
     */
    public function run( int $seasonId, callable $criticalSection ): mixed;
}
