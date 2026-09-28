<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Support;

/**
 * Starting a transaction and actually checking that it started.
 *
 * WHY THIS EXISTS: every transactional method in this plugin used to call
 * `$wpdb->query( 'START TRANSACTION' )` and ignore the result. $wpdb->query()
 * returns false when the statement fails, so the code carried on believing it
 * was inside a transaction — and the ROLLBACK in the catch block then reverted
 * nothing at all, because there was no transaction to revert.
 *
 * That is the same defect this feature has hit repeatedly: the safety
 * machinery written, present, and wired to nothing. It matters more since
 * Solicitudes\SolicitudRepository::publicarLote() exists, because the Friday
 * batch is all-or-nothing by design — a half-applied batch is worse than none,
 * since nobody would know which changes landed and the fecha is played the next
 * day.
 *
 * A real case where the statement does fail: a nested BEGIN. MySQL implicitly
 * commits the outer transaction, and the SQLite test shim refuses it outright.
 * That is precisely why PlazaRepository grew its *WithinTransaction variants —
 * and it is also why this check is not theoretical.
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
}
