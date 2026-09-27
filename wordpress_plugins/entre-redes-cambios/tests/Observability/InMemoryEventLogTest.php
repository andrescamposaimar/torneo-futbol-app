<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Observability;

use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

class InMemoryEventLogTest extends TestCase {

    public function test_records_events_in_order(): void {
        $log = new InMemoryEventLog();

        $log->record( 'plaza.abierta', [ 'plaza_id' => 1 ] );
        $log->record( 'escritura.fallida', [ 'motivo' => 'x' ] );

        $this->assertSame( 2, $log->count() );
        $this->assertSame( 'plaza.abierta', $log->all()[0]['evento'] );
        $this->assertSame( 'escritura.fallida', $log->all()[1]['evento'] );
        $this->assertSame( 'escritura.fallida', $log->last()['evento'] );
    }

    public function test_has_checks_by_evento_code(): void {
        $log = new InMemoryEventLog();
        $log->record( 'capitan.designado', [] );

        $this->assertTrue( $log->has( 'capitan.designado' ) );
        $this->assertFalse( $log->has( 'capitan.revocado' ) );
    }

    public function test_last_is_null_when_nothing_was_recorded(): void {
        $this->assertNull( ( new InMemoryEventLog() )->last() );
    }
}
