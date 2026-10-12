<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes;

use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudAbiertaException;
use PHPUnit\Framework\TestCase;

class SolicitudAbiertaExceptionTest extends TestCase {

    public function test_returns_null_for_a_dictamen_without_a_refusing_code(): void {
        $this->assertNull( SolicitudAbiertaException::desdeDictamen( Dictamen::from( [ new Motivo( 'puntaje_excede_techo', 'x' ) ] ) ) );
        $this->assertNull( SolicitudAbiertaException::desdeDictamen( Dictamen::from( [] ) ) );
    }

    /**
     * When both refuse, the PLAZA code wins regardless of the order the
     * motivos arrive in — the entrante may belong to another team's request,
     * the plaza is the one the captain can see and act on.
     */
    public function test_the_plaza_code_wins_when_both_are_present_in_either_order(): void {
        $plaza    = new Motivo( 'plaza_con_solicitud_abierta', 'p' );
        $entrante = new Motivo( 'entrante_con_solicitud_abierta', 'e' );

        $this->assertSame( 'plaza_con_solicitud_abierta', SolicitudAbiertaException::desdeDictamen( Dictamen::from( [ $entrante, $plaza ] ) )?->codigo() );
        $this->assertSame( 'plaza_con_solicitud_abierta', SolicitudAbiertaException::desdeDictamen( Dictamen::from( [ $plaza, $entrante ] ) )?->codigo() );
    }

    public function test_the_entrante_code_alone_is_reported(): void {
        $this->assertSame(
            'entrante_con_solicitud_abierta',
            SolicitudAbiertaException::desdeDictamen( Dictamen::from( [ new Motivo( 'entrante_con_solicitud_abierta', 'e' ) ] ) )?->codigo()
        );
    }
}
