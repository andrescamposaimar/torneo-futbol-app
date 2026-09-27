<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\PlazaConOcupacionVigente;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class PlazaConOcupacionVigenteTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_when_the_chain_has_a_vigent_link(): void {
        $ctx = $this->ctxFavorableSustitucion();

        $this->assertNull( ( new PlazaConOcupacionVigente() )->evaluate( $ctx ) );
    }

    public function test_fails_when_every_link_is_closed(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'ocupaciones' => [ $this->ocupacion( [ 'fecha_hasta_id' => 5, 'cerrada_por' => 'reemplazada' ] ) ],
            ]
        );

        $motivo = ( new PlazaConOcupacionVigente() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_sin_ocupacion_vigente', $motivo->codigo() );
    }

    public function test_fails_when_the_chain_is_empty(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'ocupaciones' => [] ] );

        $motivo = ( new PlazaConOcupacionVigente() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_sin_ocupacion_vigente', $motivo->codigo() );
    }

    public function test_applies_to_a_regreso_too(): void {
        $ctx = $this->ctxFavorableRegreso(
            [
                'ocupaciones' => [ $this->ocupacion( [ 'fecha_hasta_id' => 5, 'cerrada_por' => 'regreso_titular' ] ) ],
            ]
        );

        $motivo = ( new PlazaConOcupacionVigente() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
    }
}
