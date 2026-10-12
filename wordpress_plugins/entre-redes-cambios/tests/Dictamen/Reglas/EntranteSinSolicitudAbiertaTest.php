<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\EntranteSinSolicitudAbierta;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class EntranteSinSolicitudAbiertaTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_when_the_entrante_is_in_no_open_solicitud(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entranteSolicitudesAbiertas' => [] ] );

        $this->assertNull( ( new EntranteSinSolicitudAbierta() )->evaluate( $ctx ) );
    }

    public function test_fails_when_the_entrante_is_in_a_pendiente_solicitud(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entranteSolicitudesAbiertas' => [ [ 'id' => 51, 'estado' => 'pendiente' ] ] ] );

        $motivo = ( new EntranteSinSolicitudAbierta() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'entrante_con_solicitud_abierta', $motivo->codigo() );
    }

    public function test_fails_when_the_entrante_is_in_an_aprobada_solicitud(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entranteSolicitudesAbiertas' => [ [ 'id' => 52, 'estado' => 'aprobada' ] ] ] );

        $this->assertSame( 'entrante_con_solicitud_abierta', ( new EntranteSinSolicitudAbierta() )->evaluate( $ctx )?->codigo() );
    }

    /**
     * The matching request may belong to ANOTHER team — its id must never
     * reach a captain through the motivo.
     */
    public function test_the_message_does_not_leak_the_other_requests_identity_but_datos_carry_it_for_the_operator(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entranteSolicitudesAbiertas' => [ [ 'id' => 777, 'estado' => 'pendiente' ] ] ] );

        $motivo = ( new EntranteSinSolicitudAbierta() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertStringNotContainsString( '777', $motivo->mensaje(), 'The MESSAGE must name nobody; the id lives in datos, for the operator.' );
        $this->assertSame( 777, $motivo->datos()['solicitud_id'] );
    }

    /**
     * A regreso's incoming player is the plaza's titular, which the assembler
     * puts in this list when ANOTHER open request already commits him — the
     * rule must fire for it like for any tipo.
     */
    public function test_applies_to_a_regreso_whose_returning_titular_is_committed_elsewhere(): void {
        $ctx = $this->ctxFavorableRegreso( [ 'entranteSolicitudesAbiertas' => [ [ 'id' => 53, 'estado' => 'pendiente' ] ] ] );

        $this->assertSame( 'entrante_con_solicitud_abierta', ( new EntranteSinSolicitudAbierta() )->evaluate( $ctx )?->codigo() );
    }

    public function test_a_regreso_with_no_competing_commitment_passes(): void {
        $ctx = $this->ctxFavorableRegreso( [ 'entranteSolicitudesAbiertas' => [] ] );

        $this->assertNull( ( new EntranteSinSolicitudAbierta() )->evaluate( $ctx ) );
    }
}
