<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\SolicitudEnPlazo;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

/**
 * plazosUtc() fixture: apertura 2026-01-01 00:00:00, cierre_regresos
 * 2026-01-06 23:59:59, cierre_solicitudes 2026-01-08 23:59:59, publicacion
 * 2026-01-09 00:00:00 — all UTC, matching ContextoDeDictamen::plazosUtc()'s
 * contract.
 */
class SolicitudEnPlazoTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_sustitucion_passes_inside_the_window(): void {
        $ctx = $this->ctxFavorableSustitucion();

        $this->assertNull( ( new SolicitudEnPlazo() )->evaluar( $ctx ) );
    }

    public function test_sustitucion_fails_before_apertura(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [ 'solicitud' => $this->solicitudSustitucion( [ 'instanteEpoch' => $this->epoch( '2025-12-31 12:00:00' ) ] ) ]
        );

        $motivo = ( new SolicitudEnPlazo() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'fuera_de_plazo', $motivo->codigo() );
    }

    public function test_sustitucion_fails_after_cierre_solicitudes(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [ 'solicitud' => $this->solicitudSustitucion( [ 'instanteEpoch' => $this->epoch( '2026-01-09 00:00:00' ) ] ) ]
        );

        $motivo = ( new SolicitudEnPlazo() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
    }

    /**
     * THE distinguishing test: a `sustitucion` submitted AFTER
     * cierre_regresos but still BEFORE cierre_solicitudes must still pass —
     * its bound is cierre_solicitudes, never cierre_regresos. See
     * SolicitudEnPlazo's class docblock, "TWO DIFFERENT DEADLINES, ONE
     * WINDOW METHOD".
     */
    public function test_sustitucion_passes_after_cierre_regresos_but_before_cierre_solicitudes(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [ 'solicitud' => $this->solicitudSustitucion( [ 'instanteEpoch' => $this->epoch( '2026-01-07 12:00:00' ) ] ) ]
        );

        $this->assertNull( ( new SolicitudEnPlazo() )->evaluar( $ctx ) );
    }

    public function test_regreso_passes_inside_its_own_window(): void {
        $ctx = $this->ctxFavorableRegreso();

        $this->assertNull( ( new SolicitudEnPlazo() )->evaluar( $ctx ) );
    }

    public function test_regreso_fails_before_apertura(): void {
        $ctx = $this->ctxFavorableRegreso(
            [ 'solicitud' => $this->solicitudRegreso( [ 'instanteEpoch' => $this->epoch( '2025-12-31 12:00:00' ) ] ) ]
        );

        $motivo = ( new SolicitudEnPlazo() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
    }

    /**
     * THE distinguishing test in the other direction: a `regreso` submitted
     * AFTER cierre_regresos must fail EVEN THOUGH it is still comfortably
     * inside cierre_solicitudes — regresos close earlier than sustituciones
     * (Tuesday vs. Thursday in the real calendar).
     */
    public function test_regreso_fails_after_cierre_regresos_even_though_still_before_cierre_solicitudes(): void {
        $ctx = $this->ctxFavorableRegreso(
            [ 'solicitud' => $this->solicitudRegreso( [ 'instanteEpoch' => $this->epoch( '2026-01-07 12:00:00' ) ] ) ]
        );

        $motivo = ( new SolicitudEnPlazo() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'fuera_de_plazo', $motivo->codigo() );
    }
}
