<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\PrioridadDePadresRespetada;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Reglas\PrioridadDePadresRespetada — a pure function of
 * DictamenContext, so every scenario is expressed purely through
 * BuildsDictamenFixtures overrides, exactly like every other Regla's test.
 * Plazas\CandidatosResolver's own suite (CandidatosResolverTest) covers HOW
 * `padresViablesParaLaPlaza()` gets computed; this file only covers what the
 * rule DOES with that number once DictamenContext already carries it.
 */
class PrioridadDePadresRespetadaTest extends TestCase {
    use BuildsDictamenFixtures;

    /**
     * THE test that protects the current, real behavior of the torneo: the
     * policy is OFF by default, so a non-padre entrante procedes EVEN WHEN
     * viable padres exist for the plaza — nothing changes for anyone until a
     * process owner explicitly turns this on.
     */
    public function test_policy_off_lets_a_non_padre_proceed_even_with_viable_padres_waiting(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsPadre'          => false,
            'padresViablesParaLaPlaza' => 3,
        ] );

        $this->assertNull( ( new PrioridadDePadresRespetada( false ) )->evaluate( $ctx ) );
    }

    public function test_policy_on_blocks_a_non_padre_when_a_viable_padre_exists_and_reports_the_count(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsPadre'          => false,
            'padresViablesParaLaPlaza' => 2,
        ] );

        $motivo = ( new PrioridadDePadresRespetada( true ) )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'prioridad_de_padres_no_respetada', $motivo->codigo() );
        $this->assertSame( 2, $motivo->datos()['padresViables'] );
        $this->assertStringContainsString( '2', $motivo->mensaje() );
    }

    public function test_policy_on_lets_a_non_padre_proceed_when_no_padre_is_viable(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsPadre'          => false,
            'padresViablesParaLaPlaza' => 0,
        ] );

        $this->assertNull( ( new PrioridadDePadresRespetada( true ) )->evaluate( $ctx ) );
    }

    public function test_policy_on_lets_a_padre_entrante_proceed_regardless_of_the_count(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsPadre'          => true,
            'padresViablesParaLaPlaza' => 5,
        ] );

        $this->assertNull( ( new PrioridadDePadresRespetada( true ) )->evaluate( $ctx ) );
    }

    public function test_does_not_apply_to_a_regreso(): void {
        $ctx = $this->ctxFavorableRegreso( [
            'entranteEsPadre'          => false,
            'padresViablesParaLaPlaza' => 4,
        ] );

        $this->assertNull( ( new PrioridadDePadresRespetada( true ) )->evaluate( $ctx ) );
    }
}
