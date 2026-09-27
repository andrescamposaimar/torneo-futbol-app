<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\EntranteDisponible;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class EntranteDisponibleTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_with_no_other_occupations(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entranteOcupacionesEnOtrasPlazas' => [] ] );

        $this->assertNull( ( new EntranteDisponible() )->evaluar( $ctx ) );
    }

    public function test_passes_when_the_other_occupations_are_all_closed(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_hasta_id' => 4, 'cerrada_por' => 'reemplazada' ] ),
                ],
            ]
        );

        $this->assertNull( ( new EntranteDisponible() )->evaluar( $ctx ) );
    }

    public function test_fails_when_a_vigent_occupation_exists_elsewhere(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_hasta_id' => null ] ),
                ],
            ]
        );

        $motivo = ( new EntranteDisponible() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'entrante_ocupa_otra_plaza_vigente', $motivo->codigo() );
    }

    public function test_does_not_apply_to_a_regreso(): void {
        $ctx = $this->ctxFavorableRegreso(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 777, 'fecha_hasta_id' => null ] ),
                ],
            ]
        );

        $this->assertNull( ( new EntranteDisponible() )->evaluar( $ctx ) );
    }
}
