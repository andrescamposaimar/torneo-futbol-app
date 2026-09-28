<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Support;

use EntreRedes\Cambios\Support\Exception\InconsistentStateException;

/**
 * Starting, committing, and rolling back a transaction — and actually
 * checking that each one of the three did what it claims to have done.
 *
 * WHY THIS EXISTS: every transactional method in this plugin used to call
 * `$wpdb->query( 'START TRANSACTION' )` / `'COMMIT'` / `'ROLLBACK'` and ignore
 * the result. `$wpdb->query()` returns `false` when the statement fails, so
 * the code carried on believing whatever it wanted to believe:
 *
 *   - A START TRANSACTION that failed left the code believing it was inside
 *     one — and the ROLLBACK in the catch block then reverted nothing at
 *     all, because there was no transaction to revert. beginTransaction()
 *     closes this gap.
 *   - A COMMIT that fails (deadlock, connection dropped mid-statement) used
 *     to fall straight through to "success" — the caller logged its own
 *     success event and returned as if every write had landed, when nothing
 *     may have. commitTransaction() below refuses to let that lie stand: it
 *     throws instead of returning, so nobody downstream can mistake an
 *     unconfirmed COMMIT for a confirmed one.
 *   - A ROLLBACK that fails after a write error leaves the database in a
 *     state genuinely UNKNOWN — some writes may have landed, some may not
 *     have — and reporting "abortado" at that point is exactly as false as
 *     reporting "publicado". rollbackTransaction() throws
 *     Exception\InconsistentStateException instead, chaining whatever
 *     exception triggered the rollback attempt as its `previous`, so the
 *     original cause is never lost behind "the rollback itself also failed".
 *
 * That is the same defect this feature has hit repeatedly: the safety
 * machinery written, present, and wired to nothing. It matters most for
 * Solicitudes\SolicitudRepository::publicarLote(), because the Friday batch
 * is all-or-nothing by design — a half-applied batch is worse than none,
 * since nobody would know which changes landed and the fecha is played the
 * next day.
 *
 * A real case where START TRANSACTION does fail: a nested BEGIN. MySQL
 * implicitly commits the outer transaction, and the SQLite test shim refuses
 * it outright. That is precisely why PlazaRepository grew its
 * *WithinTransaction variants — and it is also why these checks are not
 * theoretical.
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
     *         the database state is now UNKNOWN, not "rolled back". Saying
     *         "abortado" at that point would be exactly as false as saying
     *         "publicado".
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
