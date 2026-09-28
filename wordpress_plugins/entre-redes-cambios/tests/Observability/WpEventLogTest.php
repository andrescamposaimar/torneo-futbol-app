<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Observability;

use EntreRedes\Cambios\Observability\WpEventLog;
use PHPUnit\Framework\TestCase;

/**
 * WpEventLog always fires the `entre_redes_cambios_event` action. It only
 * ever writes to `error_log()` — nothing else — and only when its injected
 * debug predicate says so (see WpEventLog's class docblock for why the
 * WP_DEBUG check is injected rather than read from the constant directly:
 * PHP constants cannot be redefined, and this suite runs every test in one
 * process).
 *
 * error_log() output is captured by redirecting PHP's own `error_log` ini
 * setting to a temp file for the duration of each test — this reads back
 * exactly what error_log() wrote, rather than inventing a parallel
 * assertion mechanism.
 */
class WpEventLogTest extends TestCase {

    private string $errorLogFile;
    private string|false $previousErrorLogSetting;

    protected function setUp(): void {
        $this->errorLogFile            = tempnam( sys_get_temp_dir(), 'cambios_error_log_' );
        $this->previousErrorLogSetting = ini_set( 'error_log', $this->errorLogFile );
    }

    protected function tearDown(): void {
        ini_set( 'error_log', $this->previousErrorLogSetting );
        @unlink( $this->errorLogFile );
    }

    public function test_record_always_fires_the_wordpress_action_with_evento_and_contexto(): void {
        $countBefore = did_action( 'entre_redes_cambios_event' );

        $captured = [];
        add_action( 'entre_redes_cambios_event', function ( string $evento, array $contexto ) use ( &$captured ): void {
            $captured = [ 'evento' => $evento, 'contexto' => $contexto ];
        }, 10, 2 );

        $log = new WpEventLog( static fn(): bool => false );
        $log->record( 'plaza.abierta', [ 'plaza_id' => 42 ] );

        $this->assertSame( $countBefore + 1, did_action( 'entre_redes_cambios_event' ) );
        $this->assertSame( 'plaza.abierta', $captured['evento'] );
        $this->assertSame( [ 'plaza_id' => 42 ], $captured['contexto'] );
    }

    public function test_record_does_not_write_to_error_log_when_debug_is_not_active(): void {
        $log = new WpEventLog( static fn(): bool => false );
        $log->record( 'plaza.abierta', [ 'plaza_id' => 42 ] );

        clearstatcache( true, $this->errorLogFile );
        $this->assertSame( '', (string) file_get_contents( $this->errorLogFile ) );
    }

    public function test_record_writes_to_error_log_when_debug_is_active(): void {
        $log = new WpEventLog( static fn(): bool => true );
        $log->record( 'escritura.fallida', [ 'plaza_id' => 42, 'motivo' => 'insert fallo' ] );

        clearstatcache( true, $this->errorLogFile );
        $written = (string) file_get_contents( $this->errorLogFile );

        $this->assertStringContainsString( 'entre-redes-cambios', $written );
        $this->assertStringContainsString( 'escritura.fallida', $written );
        $this->assertStringContainsString( 'insert fallo', $written );
    }

    public function test_default_constructor_reads_the_real_wp_debug_constant(): void {
        // No override passed — WP_DEBUG is undefined in this suite (see
        // tests/wp-shim.php), so defined('WP_DEBUG') is false and nothing
        // must be written, exactly like test_record_does_not_write_to_error_log_when_debug_is_not_active().
        $log = new WpEventLog();
        $log->record( 'plaza.abierta', [ 'plaza_id' => 42 ] );

        clearstatcache( true, $this->errorLogFile );
        $this->assertSame( '', (string) file_get_contents( $this->errorLogFile ) );
    }
}
