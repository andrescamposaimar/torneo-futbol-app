<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Observability;

use EntreRedes\Credencial\Observability\WpEventLog;
use PHPUnit\Framework\TestCase;

/**
 * WpEventLog always fires the `entre_redes_credencial_event` action. It only
 * ever writes to `error_log()` — nothing else — and only when its injected
 * debug predicate says so (see WpEventLog's class docblock for why the
 * WP_DEBUG check is injected rather than read from the constant directly).
 * Adapted from entre-redes-cambios/tests/Observability/WpEventLogTest.php.
 */
class WpEventLogTest extends TestCase {

    private string $errorLogFile;
    private string|false $previousErrorLogSetting;

    protected function setUp(): void {
        $this->errorLogFile            = tempnam( sys_get_temp_dir(), 'credencial_error_log_' );
        $this->previousErrorLogSetting = ini_set( 'error_log', $this->errorLogFile );
    }

    protected function tearDown(): void {
        ini_set( 'error_log', $this->previousErrorLogSetting );
        @unlink( $this->errorLogFile );
    }

    public function test_record_always_fires_the_wordpress_action_with_evento_and_contexto(): void {
        $countBefore = did_action( 'entre_redes_credencial_event' );

        $captured = [];
        add_action( 'entre_redes_credencial_event', function ( string $evento, array $contexto ) use ( &$captured ): void {
            $captured = [ 'evento' => $evento, 'contexto' => $contexto ];
        }, 10, 2 );

        $log = new WpEventLog( static fn(): bool => false );
        $log->record( 'issuance.resolve_failed', [ 'player_id' => 42 ] );

        $this->assertSame( $countBefore + 1, did_action( 'entre_redes_credencial_event' ) );
        $this->assertSame( 'issuance.resolve_failed', $captured['evento'] );
        $this->assertSame( [ 'player_id' => 42 ], $captured['contexto'] );
    }

    public function test_record_does_not_write_to_error_log_when_debug_is_not_active(): void {
        $log = new WpEventLog( static fn(): bool => false );
        $log->record( 'issuance.resolve_failed', [ 'player_id' => 42 ] );

        clearstatcache( true, $this->errorLogFile );
        $this->assertSame( '', (string) file_get_contents( $this->errorLogFile ) );
    }

    public function test_record_writes_to_error_log_when_debug_is_active(): void {
        $log = new WpEventLog( static fn(): bool => true );
        $log->record( 'runtime.limits_low', [ 'motivo' => 'memory_limit bajo' ] );

        clearstatcache( true, $this->errorLogFile );
        $written = (string) file_get_contents( $this->errorLogFile );

        $this->assertStringContainsString( 'entre-redes-credencial', $written );
        $this->assertStringContainsString( 'runtime.limits_low', $written );
        $this->assertStringContainsString( 'memory_limit bajo', $written );
    }

    public function test_default_constructor_reads_the_real_wp_debug_constant(): void {
        // No override passed — WP_DEBUG is undefined in this suite (see
        // tests/wp-shim.php), so defined('WP_DEBUG') is false and nothing
        // must be written.
        $log = new WpEventLog();
        $log->record( 'issuance.resolve_failed', [ 'player_id' => 42 ] );

        clearstatcache( true, $this->errorLogFile );
        $this->assertSame( '', (string) file_get_contents( $this->errorLogFile ) );
    }

    /**
     * THE guarantee this class exists to make: a listener registered by code
     * this plugin does not control must never be able to take down the
     * caller of record() — see class docblock, "record() NEVER PROPAGATES".
     */
    public function test_record_swallows_a_throwing_listener_instead_of_propagating(): void {
        $listener = static function (): void {
            throw new \RuntimeException( 'a completely unrelated plugin blew up in here' );
        };
        add_action( 'entre_redes_credencial_event', $listener, 10, 2 );

        try {
            $log = new WpEventLog( static fn(): bool => false );

            // The whole point: this must NOT throw.
            $log->record( 'issuance.resolve_failed', [ 'player_id' => 42 ] );

            clearstatcache( true, $this->errorLogFile );
            $this->assertStringContainsString(
                'EventLog listener failed',
                (string) file_get_contents( $this->errorLogFile ),
                'A swallowed listener failure must still leave SOME trace, via error_log() as the last resort.'
            );
        } finally {
            remove_action( 'entre_redes_credencial_event', $listener, 10 );
        }
    }

    public function test_a_throwing_listener_never_fails_the_callers_own_operation(): void {
        $listener = static function (): void {
            throw new \RuntimeException( 'listener blew up' );
        };
        add_action( 'entre_redes_credencial_event', $listener, 10, 2 );

        try {
            $log = new WpEventLog( static fn(): bool => false );

            $resultadoDeLaOperacion = 'ok';
            $log->record( 'issuance.resolve_failed', [ 'player_id' => 1 ] );

            $this->assertSame( 'ok', $resultadoDeLaOperacion, 'The caller must reach this line unaffected.' );
        } finally {
            remove_action( 'entre_redes_credencial_event', $listener, 10 );
        }
    }
}
