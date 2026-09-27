<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use PHPUnit\Framework\TestCase;

class SolicitudDeCambioTest extends TestCase {

    public function test_sustitucion_carries_the_entrante(): void {
        $solicitud = SolicitudDeCambio::sustitucion( 1, 10, 100, 888, 5, 1000 );

        $this->assertTrue( $solicitud->isSustitucion() );
        $this->assertFalse( $solicitud->isRegreso() );
        $this->assertSame( SolicitudDeCambio::TIPO_SUSTITUCION, $solicitud->tipo() );
        $this->assertSame( 888, $solicitud->entrantePlayerId() );
        $this->assertSame( 1, $solicitud->seasonId() );
        $this->assertSame( 10, $solicitud->teamId() );
        $this->assertSame( 100, $solicitud->plazaId() );
        $this->assertSame( 5, $solicitud->fechaId() );
        $this->assertSame( 1000, $solicitud->instanteEpoch() );
    }

    public function test_regreso_never_carries_an_entrante(): void {
        $solicitud = SolicitudDeCambio::regreso( 1, 10, 100, 5, 1000 );

        $this->assertTrue( $solicitud->isRegreso() );
        $this->assertFalse( $solicitud->isSustitucion() );
        $this->assertSame( SolicitudDeCambio::TIPO_REGRESO, $solicitud->tipo() );
        $this->assertNull( $solicitud->entrantePlayerId() );
    }
}
