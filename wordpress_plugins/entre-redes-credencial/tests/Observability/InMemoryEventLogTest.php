<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Observability;

use EntreRedes\Credencial\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

class InMemoryEventLogTest extends TestCase {

    public function test_records_events_in_order(): void {
        $log = new InMemoryEventLog();

        $log->record( 'issuance.resolve_failed', [ 'player_id' => 1 ] );
        $log->record( 'runtime.limits_low', [ 'motivo' => 'x' ] );

        $this->assertSame( 2, $log->count() );
        $this->assertSame( 'issuance.resolve_failed', $log->all()[0]['evento'] );
        $this->assertSame( 'runtime.limits_low', $log->all()[1]['evento'] );
        $this->assertSame( 'runtime.limits_low', $log->last()['evento'] );
    }

    public function test_has_checks_by_evento_code(): void {
        $log = new InMemoryEventLog();
        $log->record( 'approve.step_failed', [] );

        $this->assertTrue( $log->has( 'approve.step_failed' ) );
        $this->assertFalse( $log->has( 'approve.publish_failed' ) );
    }

    public function test_last_is_null_when_nothing_was_recorded(): void {
        $this->assertNull( ( new InMemoryEventLog() )->last() );
    }
}
