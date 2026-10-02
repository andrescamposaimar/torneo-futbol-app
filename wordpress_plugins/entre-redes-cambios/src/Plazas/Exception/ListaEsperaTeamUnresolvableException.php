<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Exception;

/**
 * `Plazas\ListaEsperaResolver::resolve()` could not produce a `sp_team` post
 * id for the "lista de espera" pseudo-team — no operator override is
 * configured AND the dynamic slug lookup found nothing (the season's own
 * term has no 4-digit year in its name, or no published `sp_team` post
 * carries the expected `lista-de-espera-{year}` slug).
 *
 * THE DECISION THIS EXCEPTION TRIGGERS: `Rest\PlazasController::listarCandidatos()`
 * does NOT catch this specifically — it propagates into that method's own
 * generic `\Throwable` catch, which logs `rest.plazas_candidatos_fallida` and
 * answers a real 500. This is deliberate: a captain's "Lista de Espera"
 * section reading as an EMPTY list is indistinguishable from "nobody signed
 * up", which is a materially wrong fact to show silently — see
 * ListaEsperaResolver's own class docblock.
 */
class ListaEsperaTeamUnresolvableException extends \RuntimeException {

    public function __construct( string $reason, ?\Throwable $previous = null ) {
        parent::__construct(
            "ListaEsperaResolver: the lista de espera team id could not be resolved — {$reason}",
            0,
            $previous
        );
    }
}
