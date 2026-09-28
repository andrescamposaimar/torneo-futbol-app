<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoEsElSaliente;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class EntranteNoEsElSalienteTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_when_entrante_is_not_the_vigent_occupant(): void {
        // Favorable fixture: vigente is 777 (titular), entrante is 888.
        $ctx = $this->ctxFavorableSustitucion();

        $this->assertNull( ( new EntranteNoEsElSaliente() )->evaluate( $ctx ) );
    }

    public function test_fails_when_entrante_is_the_vigent_occupant(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'solicitud' => $this->solicitudSustitucion( [ 'entrantePlayerId' => 777 ] ),
            ]
        );

        $motivo = ( new EntranteNoEsElSaliente() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'entrante_es_el_saliente', $motivo->codigo() );
    }

    public function test_fails_against_a_suplente_vigente_not_just_the_titular(): void {
        // "El cambio de cambio": the vigent occupant is a suplente (999),
        // not the titular — the same check must still fire.
        $ctx = $this->ctxFavorableSustitucion(
            [
                'ocupaciones' => [
                    $this->ocupacion( [ 'fecha_hasta_id' => 9, 'cerrada_por' => 'reemplazada' ] ),
                    $this->ocupacion(
                        [
                            'id'             => 2,
                            'player_id'      => 999,
                            'es_genesis'     => 0,
                            'fecha_desde_id' => 9,
                            'fecha_hasta_id' => null,
                            'cerrada_por'    => null,
                        ]
                    ),
                ],
                'solicitud' => $this->solicitudSustitucion( [ 'entrantePlayerId' => 999 ] ),
            ]
        );

        $motivo = ( new EntranteNoEsElSaliente() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'entrante_es_el_saliente', $motivo->codigo() );
    }

    public function test_does_not_apply_to_a_regreso(): void {
        $ctx = $this->ctxFavorableRegreso();

        $this->assertNull( ( new EntranteNoEsElSaliente() )->evaluate( $ctx ) );
    }

    public function test_passes_when_the_plaza_has_no_vigent_occupation(): void {
        // Delegated to Reglas\PlazaConOcupacionVigente; nothing to compare
        // the entrante against here.
        $ctx = $this->ctxFavorableSustitucion(
            [
                'ocupaciones' => [ $this->ocupacion( [ 'fecha_hasta_id' => 9, 'cerrada_por' => 'reemplazada' ] ) ],
            ]
        );

        $this->assertNull( ( new EntranteNoEsElSaliente() )->evaluate( $ctx ) );
    }
}
