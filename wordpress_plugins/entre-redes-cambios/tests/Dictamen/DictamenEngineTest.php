<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\ContextoDeDictamen;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\MotorDeDictamen;
use EntreRedes\Cambios\Dictamen\PoliticaBloqueoReemplazo;
use EntreRedes\Cambios\Dictamen\Regla;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteDisponible;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoBloqueado;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoEsElSaliente;
use EntreRedes\Cambios\Dictamen\Reglas\PlazaConOcupacionVigente;
use EntreRedes\Cambios\Dictamen\Reglas\PuntajeDentroDelTecho;
use EntreRedes\Cambios\Dictamen\Reglas\RegresoSoloConMinimoCumplido;
use EntreRedes\Cambios\Dictamen\Reglas\SolicitudEnPlazo;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class MotorDeDictamenTest extends TestCase {
    use BuildsDictamenFixtures;

    /** @return Regla[] */
    private function reglasCompletas( ?PoliticaBloqueoReemplazo $politicaCC5b = null ): array {
        return [
            new PuntajeDentroDelTecho(),
            new EntranteNoBloqueado( $politicaCC5b ),
            new EntranteDisponible(),
            new EntranteNoEsElSaliente(),
            new SolicitudEnPlazo(),
            new PlazaConOcupacionVigente(),
            new RegresoSoloConMinimoCumplido(),
        ];
    }

    public function test_a_fully_compliant_sustitucion_procede_with_no_motivos(): void {
        $motor    = new MotorDeDictamen( $this->reglasCompletas() );
        $dictamen = $motor->evaluar( $this->ctxFavorableSustitucion() );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }

    public function test_a_fully_compliant_regreso_procede_with_no_motivos(): void {
        $motor    = new MotorDeDictamen( $this->reglasCompletas() );
        $dictamen = $motor->evaluar( $this->ctxFavorableRegreso() );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }

    public function test_a_regreso_before_the_minimo_does_not_procede_and_reports_how_many_fechas_are_missing(): void {
        $motor    = new MotorDeDictamen( $this->reglasCompletas() );
        $ctx      = $this->ctxFavorableRegreso( [ 'countResolvedFechasSinceFn' => static fn ( int $fechaId ): int => 1 ] );
        $dictamen = $motor->evaluar( $ctx );

        $this->assertFalse( $dictamen->procede() );
        $this->assertSame( 2, $dictamen->fechasFaltantesParaLiberacion() );
    }

    /**
     * THE test that protects the design decision the whole slice exists
     * for: a solicitud that violates THREE independent rules must report
     * THREE motivos, never truncated to the first one found.
     */
    public function test_a_solicitud_that_violates_three_rules_reports_all_three_motivos(): void {
        $motor = new MotorDeDictamen( $this->reglasCompletas() );

        $ctx = $this->ctxFavorableSustitucion(
            [
                // (1) Puntaje excede el techo.
                'entrantePuntaje' => Puntaje::fromDecimal( 5.0 ),
                // (2) El entrante ya es el saliente vigente.
                'solicitud' => $this->solicitudSustitucion(
                    [
                        'entrantePlayerId' => 777,
                        // (3) Fuera de plazo.
                        'instanteEpoch'    => $this->epoch( '2026-01-09 00:00:00' ),
                    ]
                ),
            ]
        );

        $dictamen = $motor->evaluar( $ctx );

        $this->assertFalse( $dictamen->procede() );

        $codigos = array_map( static fn ( Motivo $m ): string => $m->codigo(), $dictamen->motivos() );

        $this->assertCount( 3, $codigos );
        $this->assertContains( 'puntaje_excede_techo', $codigos );
        $this->assertContains( 'entrante_es_el_saliente', $codigos );
        $this->assertContains( 'fuera_de_plazo', $codigos );
    }

    /**
     * "El cambio de cambio": succeeding a plaza whose vigent ocupación is a
     * suplente (999), NOT the titular — evaluated with the exact same
     * ruleset, no special branch anywhere in MotorDeDictamen or any Regla.
     * This is the test that proves the chain model (Plazas\CadenaResolver)
     * was worth it.
     */
    public function test_cambio_de_cambio_is_evaluated_with_the_same_rules_no_special_case(): void {
        $motor = new MotorDeDictamen( $this->reglasCompletas() );

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
                'solicitud' => $this->solicitudSustitucion( [ 'entrantePlayerId' => 1010 ] ),
            ]
        );

        $dictamen = $motor->evaluar( $ctx );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }
}
