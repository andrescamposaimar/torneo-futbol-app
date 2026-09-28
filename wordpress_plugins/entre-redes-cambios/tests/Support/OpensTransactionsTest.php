<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Support;

use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Support\Exception\InconsistentStateException;
use EntreRedes\Cambios\Support\OpensTransactions;
use PHPUnit\Framework\TestCase;

/**
 * A transaction that never started, never committed, or never rolled back
 * must all stop the caller cold — never let it believe something happened
 * when the database itself never confirmed it.
 *
 * $wpdb->query() returns false when the statement fails, and every
 * transactional method in this plugin used to ignore that for all three
 * statements — carrying on as if the transaction had started, committed, or
 * rolled back regardless of what wpdb actually reported:
 *
 *   - A START TRANSACTION that silently failed left a ROLLBACK in the catch
 *     block reverting nothing at all — see beginTransaction()'s own tests.
 *   - A COMMIT that silently failed let the caller report success (and log
 *     its own "published" event) for writes that may never have landed.
 *   - A ROLLBACK that silently failed after a write error let the caller
 *     report "abortado" (nothing applied) when the database state was
 *     actually unknown — exactly as false as reporting "publicado".
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
                commitTransaction as public callCommitTransaction;
                rollbackTransaction as public callRollbackTransaction;
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

    // -------------------------------------------------------------------------
    // commitTransaction() — a COMMIT that fails must never read as success.
    // -------------------------------------------------------------------------

    /**
     * A wpdb whose COMMIT fails — same shape as wpdbWhoseTransactionFails(),
     * targeting the COMMIT statement instead of START TRANSACTION.
     */
    private function wpdbWhoseCommitFails(): \wpdb {
        return new class() extends \wpdb {
            public function __construct() {
                $this->prefix = 'wp_';
            }

            public function query( string $sql ): int|false {
                if ( str_contains( $sql, 'COMMIT' ) ) {
                    $this->last_error = 'connection lost mid-commit';

                    return false;
                }

                return 0;
            }
        };
    }

    public function test_a_commit_that_fails_throws_instead_of_reporting_success(): void {
        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $this->wpdbWhoseCommitFails(), $log );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/must never believe/' );

        $host->callCommitTransaction( 'publicarLote' );
    }

    public function test_the_commit_failure_is_recorded_before_the_throw_with_its_context(): void {
        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $this->wpdbWhoseCommitFails(), $log );

        try {
            $host->callCommitTransaction( 'publicarLote', [ 'ids' => [ 1, 2 ] ] );
            $this->fail( 'expected the guard to throw' );
        } catch ( \RuntimeException $e ) {
            // The event must exist even though the exception propagated.
        }

        $this->assertTrue( $log->has( 'transaccion.commit_fallido' ) );

        $evento = $log->last();
        $this->assertSame( 'publicarLote', $evento['contexto']['operacion'] );
        $this->assertSame( [ 1, 2 ], $evento['contexto']['ids'] );
        $this->assertNotSame( '', (string) $evento['contexto']['last_error'] );
    }

    public function test_a_commit_that_succeeds_returns_without_logging(): void {
        global $wpdb;

        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $wpdb, $log );

        $wpdb->query( 'START TRANSACTION' );
        $host->callCommitTransaction( 'openPlaza' );

        $this->assertSame( 0, $log->count() );
    }

    // -------------------------------------------------------------------------
    // rollbackTransaction() — a ROLLBACK that fails leaves the database state
    // UNKNOWN, never "abortado" — see InconsistentStateException's docblock.
    // -------------------------------------------------------------------------

    /** A wpdb whose ROLLBACK fails. */
    private function wpdbWhoseRollbackFails(): \wpdb {
        return new class() extends \wpdb {
            public function __construct() {
                $this->prefix = 'wp_';
            }

            public function query( string $sql ): int|false {
                if ( str_contains( $sql, 'ROLLBACK' ) ) {
                    $this->last_error = 'connection lost mid-rollback';

                    return false;
                }

                return 0;
            }
        };
    }

    public function test_a_rollback_that_fails_throws_inconsistent_state_exception(): void {
        $log      = new InMemoryEventLog();
        $host     = $this->hostWith( $this->wpdbWhoseRollbackFails(), $log );
        $original = new \RuntimeException( 'insert cambios_ocupacion failed' );

        $this->expectException( InconsistentStateException::class );

        $host->callRollbackTransaction( 'succeedOcupacion', $original );
    }

    /**
     * The whole reason $causaOriginal is a required parameter, not an
     * afterthought: a failed rollback must never hide what it was trying to
     * undo behind "the rollback itself also failed".
     */
    public function test_a_failed_rollback_chains_the_original_cause_as_previous(): void {
        $log      = new InMemoryEventLog();
        $host     = $this->hostWith( $this->wpdbWhoseRollbackFails(), $log );
        $original = new \RuntimeException( 'insert cambios_ocupacion failed' );

        try {
            $host->callRollbackTransaction( 'succeedOcupacion', $original );
            $this->fail( 'expected InconsistentStateException' );
        } catch ( InconsistentStateException $e ) {
            $this->assertSame( $original, $e->getPrevious() );
        }
    }

    public function test_the_rollback_failure_is_recorded_before_the_throw_with_its_context(): void {
        $log      = new InMemoryEventLog();
        $host     = $this->hostWith( $this->wpdbWhoseRollbackFails(), $log );
        $original = new \RuntimeException( 'insert cambios_ocupacion failed' );

        try {
            $host->callRollbackTransaction( 'succeedOcupacion', $original, [ 'plaza_id' => 42 ] );
            $this->fail( 'expected InconsistentStateException' );
        } catch ( InconsistentStateException $e ) {
            // expected
        }

        $this->assertTrue( $log->has( 'transaccion.rollback_fallido' ) );

        $evento = $log->last();
        $this->assertSame( 'succeedOcupacion', $evento['contexto']['operacion'] );
        $this->assertSame( 42, $evento['contexto']['plaza_id'] );
        $this->assertNotSame( '', (string) $evento['contexto']['last_error'] );
    }

    public function test_a_rollback_that_succeeds_returns_without_logging(): void {
        global $wpdb;

        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $wpdb, $log );

        $wpdb->query( 'START TRANSACTION' );
        $host->callRollbackTransaction( 'openPlaza', new \RuntimeException( 'original write failure' ) );

        $this->assertSame( 0, $log->count() );
    }
}
