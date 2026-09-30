<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Support;

use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Support\Exception\InconsistentStateException;
use EntreRedes\Credencial\Support\OpensTransactions;
use PHPUnit\Framework\TestCase;

/**
 * Ported from entre-redes-cambios/tests/Support/OpensTransactionsTest.php —
 * see that file's own docblock for the full incident writeup. Approval\
 * ApprovalRequestRepository (design D10/D8: insert pending + blob in one
 * transaction) is this plugin's first user of the trait, so the same three
 * guarantees matter here: a START TRANSACTION that silently failed must
 * never let a later ROLLBACK believe it reverted something; a COMMIT that
 * silently failed must never let the caller report a pending photo request
 * as created when it was not; a ROLLBACK that silently failed after a write
 * error must report "unknown", never "reverted".
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
     * A wpdb whose START TRANSACTION fails. Deliberately does NOT call
     * parent::__construct(): these tests never issue another query through
     * it, so it needs no PDO.
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

        $host->callBeginTransaction( 'createPendingPhotoRequest', [ 'player_id' => 7 ] );
    }

    public function test_the_failure_is_recorded_before_the_throw_with_its_context(): void {
        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $this->wpdbWhoseTransactionFails(), $log );

        try {
            $host->callBeginTransaction( 'createPendingPhotoRequest', [ 'player_id' => 7 ] );
            $this->fail( 'expected the guard to throw' );
        } catch ( \RuntimeException $e ) {
            // The event must exist even though the exception propagated —
            // logging after the throw would lose the only trace there is.
        }

        $this->assertTrue( $log->has( 'transaccion.no_iniciada' ) );

        $evento = $log->last();
        $this->assertSame( 'createPendingPhotoRequest', $evento['contexto']['operacion'] );
        $this->assertSame( 7, $evento['contexto']['player_id'] );
        $this->assertNotSame( '', (string) $evento['contexto']['last_error'] );
    }

    public function test_a_transaction_that_starts_normally_returns_without_logging(): void {
        global $wpdb;

        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $wpdb, $log );

        $host->callBeginTransaction( 'createPendingPhotoRequest' );
        $wpdb->query( 'ROLLBACK' );

        $this->assertSame( 0, $log->count() );
    }

    // -------------------------------------------------------------------------
    // commitTransaction() — a COMMIT that fails must never read as success.
    // -------------------------------------------------------------------------

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

        $host->callCommitTransaction( 'createPendingPhotoRequest' );
    }

    public function test_the_commit_failure_is_recorded_before_the_throw_with_its_context(): void {
        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $this->wpdbWhoseCommitFails(), $log );

        try {
            $host->callCommitTransaction( 'createPendingPhotoRequest', [ 'player_id' => 7 ] );
            $this->fail( 'expected the guard to throw' );
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $log->has( 'transaccion.commit_fallido' ) );

        $evento = $log->last();
        $this->assertSame( 'createPendingPhotoRequest', $evento['contexto']['operacion'] );
        $this->assertSame( 7, $evento['contexto']['player_id'] );
        $this->assertNotSame( '', (string) $evento['contexto']['last_error'] );
    }

    public function test_a_commit_that_succeeds_returns_without_logging(): void {
        global $wpdb;

        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $wpdb, $log );

        $wpdb->query( 'START TRANSACTION' );
        $host->callCommitTransaction( 'createPendingPhotoRequest' );

        $this->assertSame( 0, $log->count() );
    }

    // -------------------------------------------------------------------------
    // rollbackTransaction() — a ROLLBACK that fails leaves the database state
    // UNKNOWN, never "abortado" — see InconsistentStateException's docblock.
    // -------------------------------------------------------------------------

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
        $original = new \RuntimeException( 'insert credencial_approval_request failed' );

        $this->expectException( InconsistentStateException::class );

        $host->callRollbackTransaction( 'createPendingPhotoRequest', $original );
    }

    public function test_a_failed_rollback_chains_the_original_cause_as_previous(): void {
        $log      = new InMemoryEventLog();
        $host     = $this->hostWith( $this->wpdbWhoseRollbackFails(), $log );
        $original = new \RuntimeException( 'insert credencial_approval_request failed' );

        try {
            $host->callRollbackTransaction( 'createPendingPhotoRequest', $original );
            $this->fail( 'expected InconsistentStateException' );
        } catch ( InconsistentStateException $e ) {
            $this->assertSame( $original, $e->getPrevious() );
        }
    }

    public function test_the_rollback_failure_is_recorded_before_the_throw_with_its_context(): void {
        $log      = new InMemoryEventLog();
        $host     = $this->hostWith( $this->wpdbWhoseRollbackFails(), $log );
        $original = new \RuntimeException( 'insert credencial_approval_request failed' );

        try {
            $host->callRollbackTransaction( 'createPendingPhotoRequest', $original, [ 'player_id' => 7 ] );
            $this->fail( 'expected InconsistentStateException' );
        } catch ( InconsistentStateException $e ) {
            // expected
        }

        $this->assertTrue( $log->has( 'transaccion.rollback_fallido' ) );

        $evento = $log->last();
        $this->assertSame( 'createPendingPhotoRequest', $evento['contexto']['operacion'] );
        $this->assertSame( 7, $evento['contexto']['player_id'] );
        $this->assertNotSame( '', (string) $evento['contexto']['last_error'] );
    }

    public function test_a_rollback_that_succeeds_returns_without_logging(): void {
        global $wpdb;

        $log  = new InMemoryEventLog();
        $host = $this->hostWith( $wpdb, $log );

        $wpdb->query( 'START TRANSACTION' );
        $host->callRollbackTransaction( 'createPendingPhotoRequest', new \RuntimeException( 'original write failure' ) );

        $this->assertSame( 0, $log->count() );
    }
}
