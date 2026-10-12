<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Lock;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Solicitudes\Exception\CreationLockUnavailableException;

/**
 * `CreationLock` over a MySQL advisory lock (`GET_LOCK` / `RELEASE_LOCK`),
 * ONE lock per season.
 *
 * WHY ONE PER SEASON, NOT PER PLAZA AND PER PLAYER: a request touches up to
 * two plazas and two players, so per-resource locks mean taking several and
 * invite deadlock (A holds P1 wants P2, B the reverse). The real volume is a
 * few dozen teams filing a handful of requests a week; serializing creation
 * within a season costs nothing and cannot deadlock.
 *
 * WHY AN ADVISORY LOCK: it needs no schema change, is not tied to a
 * transaction (`SolicitudRepository::crear()` inserts in autocommit, so the
 * row is committed before the lock is released), and MySQL itself releases it
 * if the connection dies — a crashed PHP request cannot leave it held. The
 * name embeds a hash of the table prefix so two WordPress installs sharing
 * one MySQL server never block each other.
 *
 * THE WAIT IS BOUNDED (`WAIT_SECONDS`): `GET_LOCK` with a timeout, never an
 * unbounded block. Only an exact `1` means acquired — `0` (timeout) and NULL
 * (error) both mean NOT acquired, and a lock that was never acquired is never
 * released.
 *
 * Not `final` — substitutable, like every collaborator in this plugin.
 */
class MysqlCreationLock implements CreationLock {

    /** Max seconds to wait for the lock before refusing with a retryable error. */
    public const WAIT_SECONDS = 5;

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    public function run( int $seasonId, callable $criticalSection ): mixed {
        $name = $this->lockName( $seasonId );

        $acquired = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, self::WAIT_SECONDS ) );

        if ( '1' !== (string) $acquired ) {
            $this->eventLog->record( 'solicitud.candado_creacion_fallido', [
                'season_id' => $seasonId,
                'mensaje'   => 'could not acquire the creation lock within ' . self::WAIT_SECONDS . 's',
            ] );

            throw new CreationLockUnavailableException( $seasonId );
        }

        try {
            return $criticalSection();
        } finally {
            $this->release( $name, $seasonId );
        }
    }

    private function release( string $name, int $seasonId ): void {
        $released = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );

        if ( '1' !== (string) $released ) {
            // Never throws from `finally`: that would mask the section's own
            // result or exception. The connection closing frees it anyway.
            $this->eventLog->record( 'solicitud.candado_creacion_liberacion_fallida', [
                'season_id' => $seasonId,
                'mensaje'   => 'RELEASE_LOCK did not report success',
            ] );
        }
    }

    private function lockName( int $seasonId ): string {
        // MySQL caps lock names at 64 characters.
        return 'erc_crear_' . substr( md5( $this->wpdb->prefix ), 0, 12 ) . '_' . $seasonId;
    }
}
