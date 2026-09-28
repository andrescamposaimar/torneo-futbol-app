<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Support;

use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Support\OpensTransactions;
use PHPUnit\Framework\TestCase;

/**
 * A transaction that never started must stop the caller cold.
 *
 * $wpdb->query() returns false when the statement fails, and every
 * transactional method in this plugin used to ignore that — carrying on as if
 * it were inside a transaction, so the ROLLBACK in its catch block reverted
 * nothing at all. The failure is entirely silent: the writes land one by one
 * and the rollback is a no-op.
 *
 * It is not hypothetical either. A nested BEGIN fails for real: MySQL
 * implicitly commits the outer transaction and the SQLite shim refuses it
 * outright, which is exactly why PlazaRepository has *WithinTransaction
 * variants.
 *
 * It matters most for Solicitudes\SolicitudRepository::publicarLote(), the
 * Friday batch, which is all-or-nothing by design: a half-applied batch is
 * worse than none, because nobody would know which changes landed and the
 * fecha is played the next day.
 */
class OpensTransactionsTest extends TestCase {

    /** Minimal host for the trait: the two properties it expects, nothing else. */
    private function hostWith( \wpdb $wpdb, InMemoryEventLog $log ): object {
        return new class( $wpdb, $log ) {
            use OpensTransactions {
                beginTransaction as public callBeginTransaction;
            }

            private \wpdb $wpdb;
            private InMemoryEventLog $eventLog;

            public function __construct( \wpdb $wpdb, InMemoryEventLog $eventLog ) {
                $this->wpdb     = $wpdb;
                $this->eventLog = $eventLog;
            }
        };
    }

    /**
     * A wpdb whose START TRANSACTION fails. It deliberately does NOT call
     * parent::__construct(): these tests never issue another query through
     * it, so it needs no PDO, and touching wpdb's private $pdo from a
     * subclass would only create a dynamic property.
     */
    private function wpdbWhoseTransactionFails(): \wpdb {
        return new class() extends \wpdb {
            public function __construct() {
                $this->prefix = 'wp_';
            }

            public function query( string $sql ): int|false {
                if ( str_contains( $sql, 'START TRANSACTION' ) ) {
                    $this->last_error = 'cannot start a transaction within a transaction';

                    return false;
                }

                return 0;
            }
        };
    }

    public function test_a_transaction_that_fails_to_start_throws_instead_of_continuing(): void {
        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $this->wpdbWhoseTransactionFails(), $log );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/rollback would silently revert nothing/' );

        $host->callBeginTransaction( 'publicarLote', [ 'solicitud_ids' => [ 1, 2 ] ] );
    }

    public function test_the_failure_is_recorded_before_the_throw_with_its_context(): void {
        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $this->wpdbWhoseTransactionFails(), $log );

        try {
            $host->callBeginTransaction( 'publicarLote', [ 'solicitud_ids' => [ 1, 2 ] ] );
            $this->fail( 'expected the guard to throw' );
        } catch ( \RuntimeException $e ) {
            // The event must exist even though the exception propagated —
            // logging after the throw would lose the only trace there is.
        }

        $this->assertTrue( $log->has( 'transaccion.no_iniciada' ) );

        $evento = $log->last();
        $this->assertSame( 'publicarLote', $evento['contexto']['operacion'] );
        $this->assertSame( [ 1, 2 ], $evento['contexto']['solicitud_ids'] );
        $this->assertNotSame( '', (string) $evento['contexto']['last_error'] );
    }

    public function test_a_transaction_that_starts_normally_returns_without_logging(): void {
        global $wpdb;

        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $wpdb, $log );

        $host->callBeginTransaction( 'openPlaza' );
        $wpdb->query( 'ROLLBACK' );

        $this->assertSame( 0, $log->count() );
    }
}
