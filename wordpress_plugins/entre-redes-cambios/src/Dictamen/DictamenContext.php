<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * Everything a Regla needs to evaluate a SolicitudDeCambio, already loaded —
 * zero DB access, zero clock, from here on. Assembling this from the real
 * database (PlazaRepository, FechaRepository, Calendario\Settings +
 * PlazosCalculator) is SLICE 4's job, not this one; every Regla in this
 * slice only ever reads from an instance handed to it.
 *
 * @phpstan-type OcupacionRow array<string, mixed>
 */
final class DictamenContext {

    private SolicitudDeCambio $solicitud;

    /** @var array<string, mixed> As returned by Plazas\PlazaRepository::findPlaza(). */
    private array $plaza;

    /**
     * The plaza's own chain, as Plazas\PlazaRepository::listOcupaciones()
     * returns it. That method THROWS on a wpdb-level read failure — see its
     * own docblock — so this is never a silently-empty stand-in for "the read
     * failed"; a genuinely empty array here would mean the plaza truly has no
     * ocupaciones, which `vigente()` below still treats as the
     * `Reglas\PlazaConOcupacionVigente` rejection it always has.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $ocupaciones;

    private ?Puntaje $entrantePuntaje;

    /**
     * ONLY the entrante's VIGENT ocupaciones in OTHER plazas of this season —
     * closed ones are deliberately not here.
     *
     * Stated precisely because the shape of this collection IS the contract:
     * Reglas\EntranteDisponible reads an empty array as "no conflict", so a
     * reader who assumes closed rows are included would conclude the wrong
     * thing from an empty one. Whoever fills this (see
     * DictamenContextAssembler) must throw rather than return [] on a query
     * failure, for the same reason.
     *
     * A future rule that needs the closed ones has to widen both this
     * contract and the query — it cannot just filter what arrives.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $entranteOcupacionesEnOtrasPlazas;

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $entrantePlazasConCierreTruncado;

    /**
     * Whether the entrante is a "padre" — see
     * Plazas\JugadorMetricasReader's docblock for how that is decided from
     * the dedicated ACF `caracter` field. `false` for a `regreso` (no
     * entrante at all, same convention as entrantePuntaje() being null) and
     * `false` when the entrante's `caracter` field could not resolve one
     * either — never a fabricated guess.
     */
    private bool $entranteEsPadre;

    /**
     * How many VIABLE padres exist for this solicitud's plaza — see
     * Plazas\CandidatosResolver::contarPadresViables(). Always `0` for a
     * `regreso`, and always `0` when Reglas\PrioridadDePadresRespetada's
     * policy is OFF or the entrante already is a padre — see
     * DictamenContextAssembler's own docblock for why this is deliberately
     * left at the cheap default instead of querying: see this property's own
     * consumer's docblock, Reglas\PrioridadDePadresRespetada, "the regla does
     * not look at this when the policy is off".
     */
    private int $padresViablesParaLaPlaza;

    /** @var array<string, string> */
    private array $plazosUtc;

    /** @var callable(int): int */
    private $countResolvedFechasSinceFn;

    /**
     * @param array<string, mixed>                          $plaza As returned by
     *        Plazas\PlazaRepository::findPlaza() — MUST be the plaza named by
     *        `$solicitud->plazaId()`.
     * @param array<int, array<string, mixed>>              $ocupaciones The
     *        SAME plaza's full chain, as
     *        Plazas\PlazaRepository::listOcupaciones() returns it.
     * @param Puntaje|null                                   $entrantePuntaje
     *        Null for a `regreso` — see SolicitudDeCambio's class docblock.
     *        For a `sustitucion`, the entrante's current puntaje.
     * @param array<int, array<string, mixed>>              $entranteOcupacionesEnOtrasPlazas
     *        ONLY the VIGENT ocupaciones the entrante holds in plazas OTHER
     *        than `$solicitud->plazaId()`, within `$solicitud->seasonId()`.
     *        Closed rows are filtered out by the query, NOT by the Regla —
     *        see this property's own docblock above for why the shape is the
     *        contract here.
     * @param array<int, array<int, array<string, mixed>>>  $entrantePlazasConCierreTruncado
     *        The FULL ocupaciones chain of every OTHER plaza where the
     *        entrante has a link closed `cerrada_por = 'trunca'` — one chain
     *        per such plaza. Used by Reglas\EntranteNoBloqueado, which reads
     *        each chain to apply whichever BloqueoReemplazoPolicy it was
     *        constructed with. An entrante with no trunca closures anywhere
     *        passes an empty array.
     * @param array<string, string>                          $plazosUtc
     *        The four plazos for `$solicitud->fechaId()`'s fecha, as
     *        Calendario\PlazosCalculator::computeUtc() returns them — i.e.
     *        UTC instants formatted 'Y-m-d H:i:s', NEVER
     *        PlazosCalculator::compute()'s civil strings. This is the exact
     *        frame Reglas\SolicitudEnPlazo compares
     *        `$solicitud->instanteEpoch()` against (converted via
     *        `gmdate()`); passing compute()'s civil output here would
     *        silently reintroduce the timezone bug PlazosCalculator's own
     *        class docblock warns about.
     * @param callable(int): int                             $countResolvedFechasSinceFn
     *        Same contract as Plazas\CadenaResolver's constructor parameter
     *        — given a `fecha_id`, how many resolved fechas have passed
     *        since it, inclusive. Reglas\RegresoSoloConMinimoCumplido wraps
     *        this in its own Plazas\CadenaResolver instance;
     *        Reglas\EntranteNoBloqueado calls it directly when evaluating
     *        BloqueoReemplazoPolicy::topeTresFechas().
     * @param bool                                            $entranteEsPadre
     *        See this property's own docblock.
     * @param int                                             $padresViablesParaLaPlaza
     *        See this property's own docblock.
     */
    public function __construct(
        SolicitudDeCambio $solicitud,
        array $plaza,
        array $ocupaciones,
        ?Puntaje $entrantePuntaje,
        array $entranteOcupacionesEnOtrasPlazas,
        array $entrantePlazasConCierreTruncado,
        array $plazosUtc,
        callable $countResolvedFechasSinceFn,
        bool $entranteEsPadre = false,
        int $padresViablesParaLaPlaza = 0
    ) {
        $this->solicitud                        = $solicitud;
        $this->plaza                            = $plaza;
        $this->ocupaciones                      = $ocupaciones;
        $this->entrantePuntaje                  = $entrantePuntaje;
        $this->entranteOcupacionesEnOtrasPlazas = $entranteOcupacionesEnOtrasPlazas;
        $this->entrantePlazasConCierreTruncado  = $entrantePlazasConCierreTruncado;
        $this->plazosUtc                        = $plazosUtc;
        $this->countResolvedFechasSinceFn       = $countResolvedFechasSinceFn;
        $this->entranteEsPadre                  = $entranteEsPadre;
        $this->padresViablesParaLaPlaza         = $padresViablesParaLaPlaza;
    }

    public function solicitud(): SolicitudDeCambio {
        return $this->solicitud;
    }

    /** @return array<string, mixed> */
    public function plaza(): array {
        return $this->plaza;
    }

    /** @return array<int, array<string, mixed>> */
    public function ocupaciones(): array {
        return $this->ocupaciones;
    }

    public function entrantePuntaje(): ?Puntaje {
        return $this->entrantePuntaje;
    }

    /** @return array<int, array<string, mixed>> */
    public function entranteOcupacionesEnOtrasPlazas(): array {
        return $this->entranteOcupacionesEnOtrasPlazas;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    public function entrantePlazasConCierreTruncado(): array {
        return $this->entrantePlazasConCierreTruncado;
    }

    /** @return array<string, string> */
    public function plazosUtc(): array {
        return $this->plazosUtc;
    }

    public function entranteEsPadre(): bool {
        return $this->entranteEsPadre;
    }

    public function padresViablesParaLaPlaza(): int {
        return $this->padresViablesParaLaPlaza;
    }

    /** @return callable(int): int */
    public function countResolvedFechasSinceFn(): callable {
        return $this->countResolvedFechasSinceFn;
    }

    /**
     * The plaza's own VIGENT link (`fecha_hasta_id IS NULL`), or null when
     * the chain handed in has none — a data problem Reglas\
     * PlazaConOcupacionVigente exists to report; every other Regla that also
     * needs "who currently occupies this plaza" reads this same accessor
     * instead of re-scanning `ocupaciones()` on its own, so there is exactly
     * ONE definition of "vigent" across the whole ruleset.
     *
     * @return array<string, mixed>|null
     */
    public function vigente(): ?array {
        foreach ( $this->ocupaciones as $ocupacion ) {
            if ( null === ( $ocupacion['fecha_hasta_id'] ?? null ) ) {
                return $ocupacion;
            }
        }

        return null;
    }
}
