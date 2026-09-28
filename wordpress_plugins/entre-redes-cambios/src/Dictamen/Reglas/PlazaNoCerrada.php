<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * A plaza the process owner has explicitly CLOSED (`cambios_plaza.closed_at`
 * non-null, via `Plazas\PlazaRepository::closePlaza()`) can never be the
 * target of a `sustitucion` or a `regreso` — closing a plaza is how the
 * operator corrects "this plaza should never have existed" (wrong team,
 * wrong titular, duplicate conformación), and nothing about that correction
 * means anything if a solicitud submitted before the closure — or one whose
 * fresh re-evaluation still clears every OTHER rule — can still get
 * published against it on the following Friday.
 *
 * WHY THIS RULE EXISTS, CONCRETELY: `Plazas\PlazaRepository::closePlaza()`
 * was shipped as a correction PRIMITIVE on the explicit understanding that
 * "nothing in Dictamen\DictamenEngine or Plazas\CadenaResolver ever calls it"
 * (see that method's own docblock) — true, but nothing ever checked
 * `closed_at` on the READ side either. A captain requests a change Monday,
 * the process owner closes the plaza Wednesday because it was opened by
 * mistake, and — absent this rule — Friday's lote would re-evaluate the
 * ORIGINAL solicitud, find every OTHER rule still clears it, and
 * `Solicitudes\SolicitudRepository::publicarLote()` would publish a real
 * occupant change onto a plaza the operator had just declared inert. The
 * correction tool would not have corrected anything.
 *
 * See `Plazas\PlazaRepository::assertPlazaNotClosed()` for the matching
 * defense-in-depth on the WRITE side — this rule is what a captain or
 * process owner actually SEES as a motivo; the repository guard is what
 * stops the write even if this rule were ever bypassed, skipped, or evaluated
 * against a stale context.
 */
final class PlazaNoCerrada implements Regla {

    private const CODE = 'plaza_cerrada';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( null === ( $ctx->plaza()['closed_at'] ?? null ) ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            'La plaza fue cerrada por el operador y ya no admite cambios.'
        );
    }
}
