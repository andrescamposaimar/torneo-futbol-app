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

        $this->assertNull( ( new EntranteDisponible() )->evaluate( $ctx ) );
    }

    public function test_passes_when_the_other_occupations_are_all_closed(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_hasta_id' => 4, 'cerrada_por' => 'reemplazada' ] ),
                ],
            ]
        );

        $this->assertNull( ( new EntranteDisponible() )->evaluate( $ctx ) );
    }

    public function test_fails_when_a_vigent_occupation_exists_elsewhere(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_hasta_id' => null ] ),
                ],
            ]
        );

        $motivo = ( new EntranteDisponible() )->evaluate( $ctx );

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

        $this->assertNull( ( new EntranteDisponible() )->evaluate( $ctx ) );
    }

    /**
     * THE scoped exemption (0.1.15): movement 1 of a grouped goalkeeper
     * reassignment — `DictamenContext::exencionArco() === true` — must NOT
     * be rejected for the field titular already vigently occupying his OWN
     * field plaza. Without the `exencionArco()` check in the rule, this is
     * EXACTLY the same fixture as `test_fails_when_a_vigent_occupation_exists_elsewhere()`
     * above and would fail.
     */
    public function test_exencion_arco_suppresses_the_vigent_elsewhere_check(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_hasta_id' => null ] ),
                ],
                'exencionArco' => true,
            ]
        );

        $this->assertNull(
            ( new EntranteDisponible() )->evaluate( $ctx ),
            'exencionArco() must suppress entrante_ocupa_otra_plaza_vigente for movement 1 of a grouped reassignment.'
        );
    }

    /**
     * THE scoping of the exemption to movement 1 ONLY: an ORDINARY
     * sustitucion (exencionArco=false, the default) whose entrante already
     * occupies another plaza vigently must still be rejected — the
     * exemption must never leak beyond the one leg it was built for.
     */
    public function test_an_ordinary_sustitucion_with_an_occupied_entrante_is_still_rejected(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entranteOcupacionesEnOtrasPlazas' => [
                    $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_hasta_id' => null ] ),
                ],
                'exencionArco' => false,
            ]
        );

        $motivo = ( new EntranteDisponible() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'entrante_ocupa_otra_plaza_vigente', $motivo->codigo() );
    }
}
