<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

use EntreRedes\Cambios\Dictamen\Reglas\EntranteDisponible;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoBloqueado;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoEsElSaliente;
use EntreRedes\Cambios\Dictamen\Reglas\PlazaConOcupacionVigente;
use EntreRedes\Cambios\Dictamen\Reglas\PlazaNoCerrada;
use EntreRedes\Cambios\Dictamen\Reglas\PrioridadDePadresRespetada;
use EntreRedes\Cambios\Dictamen\Reglas\PuntajeDentroDelTecho;
use EntreRedes\Cambios\Dictamen\Reglas\RegresoSoloConMinimoCumplido;
use EntreRedes\Cambios\Dictamen\Reglas\SolicitudEnPlazo;

/**
 * THE single production source of "which rules make up the dictamen
 * ruleset". Before this class existed, that list lived only inside
 * DictamenEngineTest::reglasCompletas() — a method whose name promised
 * completeness but whose only enforcement was "the test author remembered
 * to keep it in sync". A caller (or a future slice) wiring
 * `new DictamenEngine([...])` by hand with one rule fewer than the real
 * ruleset would produce no error and no red test — only a dictamen that is
 * silently more permissive than the reglamento, forever, until someone
 * notices a solicitud that should have been rejected. This is the exact same
 * shape of bug as `Calendario\EstadoDeriver` forgetting a state.
 *
 * `create()` is the ONLY place — production code or test — that is allowed
 * to know the full list. DictamenEngineTest consumes this factory instead of
 * keeping its own copy, so "forgetting a rule" becomes impossible to do
 * silently: the test and every real caller share one list, and adding a rule
 * later (as Reglas\PrioridadDePadresRespetada did — the ninth) is a one-line
 * change here that every consumer picks up automatically.
 */
final class DictamenEngineFactory {

    /**
     * @param BloqueoReemplazoPolicy|null $politicaCC5b The policy
     *        Reglas\EntranteNoBloqueado applies — see that class's and
     *        BloqueoReemplazoPolicy's docblocks for CC5b, the pending
     *        confirmation from the process owner. Null keeps
     *        EntranteNoBloqueado's own default (`topeTresFechas()`); pass an
     *        explicit policy only to evaluate the OTHER reading, never as a
     *        substitute for the process owner's eventual answer (see
     *        README's "Contracts for slice 4", point 3).
     * @param bool                        $prioridadPadresActiva Forwarded
     *        verbatim to Reglas\PrioridadDePadresRespetada — see that class's
     *        own docblock. Default `false` mirrors
     *        Calendario\Settings::prioridadPadresActiva()'s own default
     *        (OFF): whoever wires this factory together (`Plugin::boot()`)
     *        must read the real setting and pass it here explicitly — this
     *        factory has zero DB access and cannot read Settings itself.
     */
    public static function create( ?BloqueoReemplazoPolicy $politicaCC5b = null, bool $prioridadPadresActiva = false ): DictamenEngine {
        return new DictamenEngine( self::reglas( $politicaCC5b, $prioridadPadresActiva ) );
    }

    /**
     * @return Regla[] Every rule this dictamen must run, in no particular
     *         order — see DictamenEngine's class docblock, "NEVER
     *         SHORT-CIRCUITS".
     */
    public static function reglas( ?BloqueoReemplazoPolicy $politicaCC5b = null, bool $prioridadPadresActiva = false ): array {
        return [
            new PuntajeDentroDelTecho(),
            new EntranteNoBloqueado( $politicaCC5b ),
            new EntranteDisponible(),
            new EntranteNoEsElSaliente(),
            new SolicitudEnPlazo(),
            new PlazaConOcupacionVigente(),
            new PlazaNoCerrada(),
            new RegresoSoloConMinimoCumplido(),
            new PrioridadDePadresRespetada( $prioridadPadresActiva ),
        ];
    }
}
