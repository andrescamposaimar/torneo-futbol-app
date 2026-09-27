<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Exception;

/**
 * The injected `countResolvedFechasSinceFn` (see CadenaResolver's
 * constructor) could not produce a trustworthy answer — either the callable
 * itself threw (in production, this wraps
 * `Calendario\FechaRepository::countResolvedFechasSince()`, which throws
 * when a `fecha_id` no longer exists in the season), or it returned a
 * NEGATIVE count, which is impossible for a real "resolved fechas since X"
 * answer and can only mean the counter itself is broken.
 *
 * THE DECISION THIS EXCEPTION TRIGGERS: CadenaResolver::isPlazaLiberable()
 * catches this exception and returns `false` — see that method's docblock
 * for the fail-closed contract. `canTitularReturn()` and
 * `listExOcupantesBloqueados()` inherit the same fail-closed behavior
 * because both delegate to `isPlazaLiberable()` rather than calling the
 * count function directly. `meetsMinimo()` and `countFechasUntilLiberacion()`
 * do NOT catch it themselves — they are the leaf methods that detect the
 * problem in the first place, so they let it propagate; only the
 * plaza-liberation decision methods swallow it into a conservative answer.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DETECT: an INFLATED count — a
 * callable that lies UPWARD, returning MORE resolved fechas than actually
 * elapsed. CadenaResolver has no season total or independent clock to
 * compare against; from here, an inflated count is indistinguishable from a
 * correct one. Capping/validating the callable's upper bound is the
 * RESPONSIBILITY OF WHOEVER PROVIDES IT — in production,
 * `Calendario\FechaRepository::countResolvedFechasSince()` — a contract any
 * later slice supplying this callable (e.g. slice 3's dictamen engine) must
 * uphold; CadenaResolver cannot enforce it.
 */
class FechaCountUnavailableException extends \RuntimeException {

    public function __construct( string $reason, ?\Throwable $previous = null ) {
        parent::__construct(
            "CadenaResolver: the resolved-fechas count is unavailable or invalid — {$reason}",
            0,
            $previous
        );
    }
}
