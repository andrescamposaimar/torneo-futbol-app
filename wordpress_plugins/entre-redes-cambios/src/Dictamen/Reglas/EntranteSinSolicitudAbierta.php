<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * A player already named as the incoming player of an OPEN solicitud —
 * `pendiente` or `aprobada`, see `Solicitudes\EstadoSolicitud::abiertas()` —
 * cannot be named again, by anyone, in the whole season. The process owner's
 * words: "no se puede solicitar que ingrese un mismo jugador dos veces o a
 * dos equipos distintos."
 *
 * WHY THE SCOPE IS THE WHOLE SEASON, NOT THE CAPTAIN'S TEAM: the player pool
 * for a plaza is every `sp_player` registered in the season, other teams'
 * included (see `Plazas\CandidatosResolver`'s docblock). Two captains can
 * therefore pick the same outside player the same afternoon, and
 * `Reglas\EntranteDisponible` cannot stop them — it only looks at occupations
 * ALREADY OPEN in `cambios_ocupacion`, and an unpublished request has not
 * opened one yet. Whichever request the lote published second would fail
 * Friday's re-evaluation, hours after both captains were told their request
 * "procede". Refusing at request time moves the failure to the moment the
 * captain can still act on it.
 *
 * *** BOTH ENTRANTE COLUMNS ***
 * `Solicitudes\SolicitudesAbiertasReader::listAbiertasPorEntrante()` matches
 * an open row on `entrante_player_id` OR `entrante_campo_player_id`. A
 * grouped `reasignacion_arquero` names TWO incoming players — the field
 * titular moving into goal, and the outside player filling the field plaza he
 * vacates — and the second is exactly as committed as the first. A lookup on
 * `entrante_player_id` alone would let that outside player be requested again
 * elsewhere. Each leg of a grouped request is its own context with its own
 * entrante (see `DictamenPipeline::evaluateGrupo()`), so this rule covers
 * both incoming players the same way `PlazaSinSolicitudAbierta` covers both
 * plazas: by reading the context it is handed and nothing more.
 *
 * A `regreso` IS covered (0.1.21): its NULL `entrante_player_id` is a storage
 * detail, and the player coming back — the plaza's `titular_player_id` — is
 * an incoming player like any other. `DictamenContextAssembler` looks THAT
 * player up for a `regreso`, so a titular already named by ANOTHER open
 * request (a sustitucion elsewhere, another regreso) refuses the return, the
 * mirror of `SolicitudesAbiertasReader` counting an open regreso's titular as
 * committed. This rule does not special-case tipo: it reads the list it is
 * handed, and the assembler excludes the request's own row, so a regreso's
 * ordinary relationship to its own titular never fires it.
 *
 * *** IT NEVER COUNTS ITSELF, AND NEVER LEAKS ANOTHER TEAM'S REQUEST ***
 * The solicitud being re-evaluated at publication time is excluded by
 * `DictamenContextAssembler`, not here — see
 * `PlazaSinSolicitudAbierta`'s docblock. And because the matching request may
 * belong to a DIFFERENT team, this motivo's MESSAGE names no request and no
 * team: a captain must not learn what a rival team has filed. Its `datos`
 * DO carry `solicitud_id` (0.1.21), for the operator — it is what lets a
 * lote abort name the conflicting row (`SolicitudRepository::publicarLote()`).
 * That id never reaches a captain: a dictamen carrying this motivo is
 * REFUSED at creation (HTTP 409 with a fixed message, nothing stored), so
 * `Rest\SolicitudesController` never shapes its `datos` into a response.
 *
 * THIS VERDICT IS ENFORCED, not advisory: unlike the ten judgement rules, a
 * dictamen carrying `entrante_con_solicitud_abierta` makes `SolicitudRepository` refuse to create the
 * solicitud at all (HTTP 409, nothing stored) — see
 * `Solicitudes\Exception\SolicitudAbiertaException` for why.
 */
final class EntranteSinSolicitudAbierta implements Regla {

    public const CODE = 'entrante_con_solicitud_abierta';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( [] === $ctx->entranteSolicitudesAbiertas() ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            'El entrante ya figura en otra solicitud abierta (pendiente o aprobada) de esta temporada y no puede pedirse de nuevo.',
            [ 'solicitud_id' => $ctx->entranteSolicitudesAbiertas()[0]['id'] ]
        );
    }
}
