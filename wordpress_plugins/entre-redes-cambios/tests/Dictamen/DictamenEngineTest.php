<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\DictamenEngine;
use EntreRedes\Cambios\Dictamen\DictamenEngineFactory;
use EntreRedes\Cambios\Dictamen\Regla;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class DictamenEngineTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_a_fully_compliant_sustitucion_procede_with_no_motivos(): void {
        $motor    = DictamenEngineFactory::create();
        $dictamen = $motor->evaluate( $this->ctxFavorableSustitucion() );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }

    public function test_a_fully_compliant_regreso_procede_with_no_motivos(): void {
        $motor    = DictamenEngineFactory::create();
        $dictamen = $motor->evaluate( $this->ctxFavorableRegreso() );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }

    public function test_a_regreso_before_the_minimo_does_not_procede_and_reports_how_many_fechas_are_missing(): void {
        $motor    = DictamenEngineFactory::create();
        $ctx      = $this->ctxFavorableRegreso( [ 'countResolvedFechasSinceFn' => static fn ( int $fechaId ): int => 1 ] );
        $dictamen = $motor->evaluate( $ctx );

        $this->assertFalse( $dictamen->procede() );
        $this->assertSame( 2, $dictamen->fechasFaltantesParaLiberacion() );
    }

    /**
     * THE test that protects the design decision the whole slice exists
     * for: a solicitud that violates THREE independent rules must report
     * THREE motivos, never truncated to the first one found.
     */
    public function test_a_solicitud_that_violates_three_rules_reports_all_three_motivos(): void {
        $motor = DictamenEngineFactory::create();

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

        $dictamen = $motor->evaluate( $ctx );

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
     * ruleset, no special branch anywhere in DictamenEngine or any Regla.
     * This is the test that proves the chain model (Plazas\CadenaResolver)
     * was worth it.
     */
    public function test_cambio_de_cambio_is_evaluated_with_the_same_rules_no_special_case(): void {
        $motor = DictamenEngineFactory::create();

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

        $dictamen = $motor->evaluate( $ctx );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }

    /**
     * THE test that protects the fail-closed contract: a Regla that throws
     * (corrupted business data it cannot evaluate at all — see
     * DictamenEngine's class docblock, "A RULE THAT THROWS NEVER TAKES THE
     * OTHERS' MOTIVOS DOWN WITH IT") must (a) NOT abort the loop, and (b)
     * still surface every motivo the OTHER rules already collected.
     */
    public function test_a_rule_that_throws_does_not_procede_and_does_not_swallow_the_other_motivos(): void {
        $throwingRegla = new class implements Regla {
            public function evaluate( DictamenContext $ctx ): ?Motivo {
                throw new \RuntimeException( 'puntaje_techo corrupto: fuera de rango' );
            }
        };

        $motor = new DictamenEngine(
            array_merge(
                [ $throwingRegla ],
                DictamenEngineFactory::reglas()
            )
        );

        // Also violates SolicitudEnPlazo, so a real motivo must survive
        // alongside the one the throwing rule produced.
        $ctx = $this->ctxFavorableSustitucion(
            [ 'solicitud' => $this->solicitudSustitucion( [ 'instanteEpoch' => $this->epoch( '2026-01-09 00:00:00' ) ] ) ]
        );

        $dictamen = $motor->evaluate( $ctx );

        $this->assertFalse( $dictamen->procede() );

        $codigos = array_map( static fn ( Motivo $m ): string => $m->codigo(), $dictamen->motivos() );

        $this->assertContains( 'error_al_evaluar_regla', $codigos, 'A throwing rule must fail closed, as its own motivo.' );
        $this->assertContains( 'fuera_de_plazo', $codigos, 'The other rules\' motivos must survive the throw, never be discarded.' );

        $motivoError = $dictamen->motivo( 'error_al_evaluar_regla' );
        $this->assertNotNull( $motivoError );
        $this->assertSame( get_class( $throwingRegla ), $motivoError->datos()['regla'] );
    }
}
