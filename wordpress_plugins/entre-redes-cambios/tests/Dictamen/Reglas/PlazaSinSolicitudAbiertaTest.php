<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\PlazaSinSolicitudAbierta;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class PlazaSinSolicitudAbiertaTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_when_the_plaza_has_no_open_solicitud(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'plazaSolicitudesAbiertas' => [] ] );

        $this->assertNull( ( new PlazaSinSolicitudAbierta() )->evaluate( $ctx ) );
    }

    public function test_fails_when_the_plaza_has_a_pendiente_solicitud(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'plazaSolicitudesAbiertas' => [ [ 'id' => 41, 'estado' => 'pendiente' ] ] ] );

        $motivo = ( new PlazaSinSolicitudAbierta() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_con_solicitud_abierta', $motivo->codigo() );
        $this->assertSame( 41, $motivo->datos()['solicitud_id'] );
    }

    public function test_fails_when_the_plaza_has_an_aprobada_solicitud_waiting_for_the_lote(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'plazaSolicitudesAbiertas' => [ [ 'id' => 42, 'estado' => 'aprobada' ] ] ] );

        $motivo = ( new PlazaSinSolicitudAbierta() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_con_solicitud_abierta', $motivo->codigo() );
    }

    /**
     * The titular coming back changes the plaza's occupant exactly like a
     * sustitucion does — the rule must not be limited to one tipo.
     */
    public function test_applies_to_a_regreso_too(): void {
        $ctx = $this->ctxFavorableRegreso( [ 'plazaSolicitudesAbiertas' => [ [ 'id' => 43, 'estado' => 'pendiente' ] ] ] );

        $motivo = ( new PlazaSinSolicitudAbierta() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_con_solicitud_abierta', $motivo->codigo() );
    }
}
