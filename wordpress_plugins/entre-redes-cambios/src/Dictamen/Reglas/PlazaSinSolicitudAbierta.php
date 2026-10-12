<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * A plaza that already has an OPEN solicitud — `pendiente` or `aprobada`,
 * see `Solicitudes\EstadoSolicitud::abiertas()` — cannot receive another one.
 *
 * WHY THIS RULE EXISTS, CONCRETELY: nothing used to stop a captain filing a
 * second request against a plaza whose first was still sitting in the
 * bandeja. Both would clear every other rule (each is judged against the
 * plaza AS IT IS TODAY, not as it will be once the other lands), the process
 * owner would have to notice the collision by eye, and if both were approved
 * `SolicitudRepository::publicarLote()` would apply them one after the other:
 * the second silently succeeding the first's entrante, a change nobody asked
 * for in that order. The process owner confirmed it as a real hole.
 *
 * `aprobada` counts as open ON PURPOSE. An approved request is not yet an
 * official change — it only becomes one at Friday's lote (see
 * `Solicitudes\EstadoSolicitud`, "THE GRAPH") — but until then the plaza is
 * already spoken for; treating `aprobada` as closed would reopen the exact
 * window this rule exists to shut, between Wednesday's approval and Friday's
 * publication.
 *
 * *** HOW A GROUPED `reasignacion_arquero` IS COVERED ***
 * `DictamenPipeline::evaluateGrupo()` evaluates the request TWICE, one
 * context per movement, each carrying its OWN plaza — so this rule, which
 * only reads `plazaSolicitudesAbiertas()` of the context it is handed,
 * already covers both the goal plaza and the vacated field plaza without
 * knowing pairs exist. The pairing lives where the data is fetched:
 * `Solicitudes\SolicitudesAbiertasReader::listAbiertasPorPlaza()` matches an
 * open row on `plaza_id` OR `plaza_campo_id`, so an ordinary substitution on
 * the field plaza is refused while a grouped request is still vacating it.
 * Do not add a "grouped" branch here.
 *
 * *** IT NEVER COUNTS ITSELF ***
 * `DictamenContextAssembler` excludes the solicitud being re-evaluated
 * (`SolicitudDeCambio::solicitudId()`) from the list this rule reads. Without
 * that, every approved request would be rejected by its own Friday
 * re-evaluation — see that accessor's docblock. The exclusion belongs in the
 * assembler, not here: this rule has no id to compare against and must stay a
 * pure reading of the context.
 *
 * Applies to EVERY tipo, a `regreso` included — the titular coming back is a
 * change of occupant of the same plaza.
 *
 * THIS VERDICT IS ENFORCED, not advisory: unlike the ten judgement rules, a
 * dictamen carrying `plaza_con_solicitud_abierta` makes `SolicitudRepository` refuse to create the
 * solicitud at all (HTTP 409, nothing stored) — see
 * `Solicitudes\Exception\SolicitudAbiertaException` for why.
 */
final class PlazaSinSolicitudAbierta implements Regla {

    public const CODE = 'plaza_con_solicitud_abierta';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        $abiertas = $ctx->plazaSolicitudesAbiertas();

        if ( [] === $abiertas ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            'La plaza ya tiene una solicitud abierta (pendiente o aprobada) y no admite otra hasta que se resuelva.',
            [ 'solicitud_id' => $abiertas[0]['id'] ]
        );
    }
}
