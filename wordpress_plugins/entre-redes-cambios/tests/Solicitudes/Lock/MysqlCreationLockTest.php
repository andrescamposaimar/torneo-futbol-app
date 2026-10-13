<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes\Lock;

use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Solicitudes\Exception\CreationLockUnavailableException;
use EntreRedes\Cambios\Solicitudes\Lock\MysqlCreationLock;
use PHPUnit\Framework\TestCase;

/**
 * Tests the CONTROL FLOW of `MysqlCreationLock` against a scripted wpdb —
 * acquire before the critical section, release after it on every exit path,
 * never release what was never acquired, a bounded wait. It deliberately does
 * NOT claim to test MySQL's `GET_LOCK` semantics (the SQLite shim has none);
 * the cross-request property is covered by the controller tests through the
 * `CreationLock` seam.
 */
class MysqlCreationLockTest extends TestCase {

    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        $this->eventLog = new InMemoryEventLog();
    }

    /**
     * @param array<int, string|null> $respuestas What get_var() answers, in call order.
     * @return array{0: MysqlCreationLock, 1: \ArrayObject<int, string>}
     */
    private function lockConRespuestas( array $respuestas ): array {
        $consultas = new \ArrayObject();

        $wpdb = new class( $respuestas, $consultas ) extends \wpdb {
            /** @param array<int, string|null> $respuestas */
            public function __construct( private array $respuestas, private \ArrayObject $consultas ) {
                parent::__construct();
            }

            public function get_var( string $sql ): ?string {
                $this->consultas[] = $sql;

                return array_shift( $this->respuestas );
            }
        };

        return [ new MysqlCreationLock( $wpdb, $this->eventLog ), $consultas ];
    }

    public function test_the_critical_section_runs_between_acquire_and_release_and_returns_its_value(): void {
        [ $lock, $consultas ] = $this->lockConRespuestas( [ '1', '1' ] );
        $durante = null;

        $resultado = $lock->run( 359, function () use ( $consultas, &$durante ) {
            $durante = count( $consultas );

            return 'valor';
        } );

        $this->assertSame( 'valor', $resultado );
        $this->assertSame( 1, $durante, 'Only GET_LOCK may have run when the section starts.' );
        $this->assertCount( 2, $consultas );
        $this->assertStringContainsString( 'GET_LOCK', $consultas[0] );
        $this->assertStringContainsString( 'RELEASE_LOCK', $consultas[1] );
    }

    public function test_it_releases_when_the_critical_section_throws(): void {
        [ $lock, $consultas ] = $this->lockConRespuestas( [ '1', '1' ] );

        try {
            $lock->run( 359, static function (): void {
                throw new \RuntimeException( 'boom' );
            } );
            $this->fail( 'The exception must propagate.' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'boom', $e->getMessage() );
        }

        $this->assertCount( 2, $consultas );
        $this->assertStringContainsString( 'RELEASE_LOCK', $consultas[1] );
    }

    /**
     * @dataProvider respuestasSinLock
     */
    public function test_when_the_lock_is_not_acquired_the_section_never_runs_and_nothing_is_released( ?string $respuesta ): void {
        [ $lock, $consultas ] = $this->lockConRespuestas( [ $respuesta ] );
        $corrio = false;

        try {
            $lock->run( 359, function () use ( &$corrio ): void {
                $corrio = true;
            } );
            $this->fail( 'Expected CreationLockUnavailableException.' );
        } catch ( CreationLockUnavailableException $e ) {
            // expected
        }

        $this->assertFalse( $corrio );
        $this->assertCount( 1, $consultas, 'A lock never acquired must never be released.' );
        $this->assertTrue( $this->eventLog->has( 'solicitud.candado_creacion_fallido' ) );
    }

    /** @return array<string, array{string|null}> */
    public static function respuestasSinLock(): array {
        return [ 'timeout (0)' => [ '0' ], 'error (NULL)' => [ null ], 'anything but 1' => [ '2' ] ];
    }

    public function test_the_wait_is_bounded_and_the_name_is_per_season_and_within_mysqls_limit(): void {
        [ $lock, $consultas ] = $this->lockConRespuestas( [ '1', '1' ] );

        $lock->run( 359, static fn () => null );

        $this->assertMatchesRegularExpression( '/GET_LOCK\( \'([^\']+)\', ' . MysqlCreationLock::WAIT_SECONDS . ' \)/', $consultas[0] );
        $this->assertGreaterThan( 0, MysqlCreationLock::WAIT_SECONDS );
        $this->assertLessThanOrEqual( 30, MysqlCreationLock::WAIT_SECONDS );

        preg_match( '/GET_LOCK\( \'([^\']+)\'/', $consultas[0], $m );
        $this->assertLessThanOrEqual( 64, strlen( $m[1] ) );
        $this->assertStringEndsWith( '_359', $m[1] );
    }

    public function test_a_failed_release_is_logged_but_never_masks_the_sections_result(): void {
        [ $lock ] = $this->lockConRespuestas( [ '1', '0' ] );

        $this->assertSame( 'ok', $lock->run( 359, static fn () => 'ok' ) );
        $this->assertTrue( $this->eventLog->has( 'solicitud.candado_creacion_liberacion_fallida' ) );
    }
}
