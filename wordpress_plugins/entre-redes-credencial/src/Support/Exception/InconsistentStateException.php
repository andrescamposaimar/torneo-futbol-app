<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Support\Exception;

/**
 * Ported from entre-redes-cambios/src/Support/Exception/InconsistentStateException.php.
 *
 * The database's state is UNKNOWN after this — thrown only when a ROLLBACK
 * itself fails at the wpdb level, following a write failure inside the same
 * transaction (see Support\OpensTransactions::rollbackTransaction()).
 *
 * Every other exception this plugin throws describes a KNOWN outcome — a
 * write failed, a rollback undid it, the caller can trust "nothing was
 * applied". Losing the ROLLBACK itself breaks that guarantee: some of the
 * transaction's writes may have landed, some may not have, and there is no
 * query this class can run to tell the difference from here. Reporting
 * "reverted" would be exactly as false as reporting "applied" — both are
 * guesses dressed up as facts. The only honest answer is "unknown", which is
 * what this exception's very existence says.
 *
 * The original exception that triggered the ROLLBACK attempt is ALWAYS
 * chained as `previous` — so whoever inspects this exception can still see
 * what the transaction was trying to undo, not just that the undo itself
 * also failed.
 */
class InconsistentStateException extends \RuntimeException {

    public function __construct( string $operacion, ?string $wpdbLastError, \Throwable $previous ) {
        parent::__construct(
            sprintf(
                '%s: ROLLBACK itself failed after a write error — the database state is now '
                . 'UNKNOWN, not "rolled back". %s',
                $operacion,
                $wpdbLastError ? "wpdb error: {$wpdbLastError}." : '(wpdb reported no error message.)'
            ),
            0,
            $previous
        );
    }
}
