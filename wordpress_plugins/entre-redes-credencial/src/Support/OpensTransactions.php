<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Support;

use EntreRedes\Credencial\Support\Exception\InconsistentStateException;

/**
 * Ported from entre-redes-cambios/src/Support/OpensTransactions.php — see
 * that file's own docblock for the full incident writeup this trait exists
 * to prevent. Approval\ApprovalRequestRepository (design D8/D10: insert a
 * pending request + its blob in one transaction) is this plugin's first
 * user: a COMMIT that silently failed must never let the caller believe a
 * pending photo request was created; a ROLLBACK that silently failed after a
 * write error must report "unknown", never "reverted".
 *
 * Starting, committing, and rolling back a transaction — and actually
 * checking that each one of the three did what it claims to have done.
 * `$wpdb->query()` returns `false` when the statement fails; ignoring that
 * for START TRANSACTION / COMMIT / ROLLBACK lets the caller carry on
 * believing whatever it wants to believe about the database's real state.
 *
 * The using class must have `$wpdb` and `$eventLog` properties.
 */
trait OpensTransactions {

    /**
     * @param array<string, mixed> $contexto Identifiers worth having in the log.
     *
     * @throws \RuntimeException When the transaction could not be started.
     */
    private function beginTransaction( string $operacion, array $contexto = [] ): void {
        if ( false !== $this->wpdb->query( 'START TRANSACTION' ) ) {
            return;
        }

        $this->eventLog->record( 'transaccion.no_iniciada', array_merge( $contexto, [
            'operacion'  => $operacion,
            'last_error' => $this->wpdb->last_error,
        ] ) );

        throw new \RuntimeException(
            sprintf(
                'Could not start a transaction for %s. Refusing to continue: without one, '
                . 'a rollback would silently revert nothing. (%s)',
                $operacion,
                (string) $this->wpdb->last_error
            )
        );
    }

    /**
     * @param array<string, mixed> $contexto Identifiers worth having in the log.
     *
     * @throws \RuntimeException When the COMMIT itself failed at the wpdb
     *         level — the caller must never treat this as success just
     *         because every write before it went through.
     */
    private function commitTransaction( string $operacion, array $contexto = [] ): void {
        if ( false !== $this->wpdb->query( 'COMMIT' ) ) {
            return;
        }

        $this->eventLog->record( 'transaccion.commit_fallido', array_merge( $contexto, [
            'operacion'  => $operacion,
            'last_error' => $this->wpdb->last_error,
        ] ) );

        throw new \RuntimeException(
            sprintf(
                'COMMIT failed for %s. Refusing to report success: the caller must never believe '
                . 'this operation was applied when the database itself could not confirm it. (%s)',
                $operacion,
                (string) $this->wpdb->last_error
            )
        );
    }

    /**
     * @param \Throwable            $causaOriginal The exception that made the
     *        caller attempt this rollback in the first place — ALWAYS chained
     *        as `previous` on InconsistentStateException, so a failed
     *        rollback never hides what it was trying to undo.
     * @param array<string, mixed> $contexto Identifiers worth having in the log.
     *
     * @throws InconsistentStateException When the ROLLBACK itself failed —
     *         the database state is now UNKNOWN, not "rolled back".
     */
    private function rollbackTransaction( string $operacion, \Throwable $causaOriginal, array $contexto = [] ): void {
        if ( false !== $this->wpdb->query( 'ROLLBACK' ) ) {
            return;
        }

        $this->eventLog->record( 'transaccion.rollback_fallido', array_merge( $contexto, [
            'operacion'  => $operacion,
            'last_error' => $this->wpdb->last_error,
        ] ) );

        throw new InconsistentStateException( $operacion, $this->wpdb->last_error, $causaOriginal );
    }
}
