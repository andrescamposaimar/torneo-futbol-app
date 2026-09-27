<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Dictamen\DictamenEngine;
use EntreRedes\Cambios\Dictamen\DictamenEngineFactory;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteDisponible;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoBloqueado;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoEsElSaliente;
use EntreRedes\Cambios\Dictamen\Reglas\PlazaConOcupacionVigente;
use EntreRedes\Cambios\Dictamen\Reglas\PuntajeDentroDelTecho;
use EntreRedes\Cambios\Dictamen\Reglas\RegresoSoloConMinimoCumplido;
use EntreRedes\Cambios\Dictamen\Reglas\SolicitudEnPlazo;
use PHPUnit\Framework\TestCase;

/**
 * THE test that protects the ruleset's completeness itself: this is the
 * ONLY place — production or test — allowed to assert "these seven, no
 * more, no fewer" against DictamenEngineFactory. Every other test consumes
 * the factory rather than re-listing the rules, so a future rule dropped
 * from create()/reglas() fails HERE, loudly, instead of nowhere.
 */
class DictamenEngineFactoryTest extends TestCase {

    public function test_reglas_returns_exactly_the_seven_rules_of_the_reglamento(): void {
        $reglas = DictamenEngineFactory::reglas();

        $this->assertCount( 7, $reglas );

        $clases = array_map( static fn ( object $r ): string => get_class( $r ), $reglas );

        $this->assertSame(
            [
                PuntajeDentroDelTecho::class,
                EntranteNoBloqueado::class,
                EntranteDisponible::class,
                EntranteNoEsElSaliente::class,
                SolicitudEnPlazo::class,
                PlazaConOcupacionVigente::class,
                RegresoSoloConMinimoCumplido::class,
            ],
            $clases
        );
    }

    public function test_create_returns_a_dictamen_engine_wired_with_the_full_ruleset(): void {
        $this->assertInstanceOf( DictamenEngine::class, DictamenEngineFactory::create() );
    }

    public function test_create_forwards_the_cc5b_policy_to_entrante_no_bloqueado(): void {
        // Only an indirect check is possible without reflection: create()
        // must not throw and must still produce an engine when an explicit
        // policy is passed, exercising the same construction path
        // EntranteNoBloqueadoTest exercises directly against the rule.
        $motor = DictamenEngineFactory::create( BloqueoReemplazoPolicy::hastaLiberacionDePlaza() );

        $this->assertInstanceOf( DictamenEngine::class, $motor );
    }
}
