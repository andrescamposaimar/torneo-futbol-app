<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

use EntreRedes\Cambios\Observability\EventLog;

/**
 * THE single production entry point to evaluate a `SolicitudDeCambio`
 * end to end: assemble its `DictamenContext` from the real database
 * (`DictamenContextAssembler`) and run it through the complete ruleset
 * (`DictamenEngineFactory::create()`). This is exactly the piece README's
 * "Contracts for slice 4" point 2 asked for — "the wrapper that invokes
 * DictamenEngine must catch \Throwable, log it with the solicitud's
 * identifiers, and only then decide what to answer the caller" — built now
 * so a later REST endpoint has ONE thing to call rather than a discipline
 * ("remember to assemble, then remember to use the factory, then remember
 * to log a failure") someone could forget.
 *
 * *** THIS DOES NOT SWALLOW A FAILURE INTO A FAKE DICTAMEN ***
 * `DictamenEngine::evaluate()` already fails closed for a single Regla that
 * throws (see its own class docblock: caught per-rule, turned into its own
 * Motivo, every other rule still runs). What THIS class additionally guards
 * against is a failure BEFORE the engine ever runs — `DictamenContextAssembler::assemble()`
 * throwing because the plaza or fecha does not exist, or because one of its
 * underlying queries failed at the wpdb level (see that class's own class
 * docblock). Catching \Throwable here logs `dictamen.fallido` with the
 * solicitud's own identifiers (season, team, plaza, fecha, tipo) — a single,
 * well-known place to look when "the subcomisión rejected it" needs to be
 * told apart from "the pipeline itself crashed" — and then RE-THROWS: no
 * REST layer exists yet to decide what HTTP answer that failure deserves
 * (see this plugin's README, "Scope of this slice"), so deciding that
 * belongs to whoever calls this class next, not to this one.
 *
 * *** `evaluateGrupo()` — THE GROUPED GOALKEEPER REASSIGNMENT (0.1.15) ***
 * A grouped request ("la exención del arco") carries TWO movements that are
 * approved or rejected as a unit — see `Solicitudes\SolicitudRepository`'s
 * class docblock for the full business shape. Rather than teach the ten
 * existing `Regla`s about pairs, `evaluateGrupo()` assembles TWO ordinary
 * `DictamenContext`s (one per movement, each still judged by the SAME
 * `DictamenEngineFactory::reglas()`) and UNIONS their motivos into one
 * `Dictamen` — see `Dictamen::from()`'s own docblock: "never truncated to
 * the first one", which is exactly what makes a plain `array_merge()` of
 * both legs' motivos a faithful "every reason, from both movements, at
 * once". Movement 1's context alone gets `$exencionArco` (gated by
 * `$exencionArcoActiva`, read from `Calendario\Settings::exencionArcoActiva()`
 * by whoever constructs this pipeline — see that setting's own docblock);
 * movement 2 is assembled exactly like `evaluate()` assembles any ordinary
 * `sustitucion`, with no exemption at all.
 *
 * *** EACH MOTIVO IS TAGGED WITH WHICH LEG PRODUCED IT (0.1.16) ***
 * Before unioning, every motivo from movement 1's Dictamen is tagged
 * `Motivo::conMovimiento('arco')` and every motivo from movement 2's is
 * tagged `conMovimiento('campo')` — see that method's own docblock. Several
 * codigos (`plaza_sin_ocupacion_vigente`, `fuera_de_plazo`,
 * `entrante_es_el_saliente`…) can come from EITHER leg, so without this a
 * process owner reading the pooled motivo list has no way to tell which
 * movement is actually the problem — defeating the whole point of judging
 * the pair together (see `Admin\BandejaPage`'s class docblock, "so the
 * process owner judges the WHOLE move, not half of it"). Each `Regla` stays
 * completely unaware this is happening: the tag is applied here, AFTER both
 * legs' Dictamen already exist, never inside `DictamenEngine::evaluate()`.
 */
final class DictamenPipeline {

    private DictamenContextAssembler $assembler;
    private EventLog $eventLog;
    private ?BloqueoReemplazoPolicy $politicaCC5b;
    private bool $prioridadPadresActiva;
    private bool $exencionArcoActiva;

    /**
     * @param BloqueoReemplazoPolicy|null $politicaCC5b Forwarded verbatim to
     *        `DictamenEngineFactory::create()` — see that method's docblock
     *        for CC5b, the still-unconfirmed policy. Null keeps
     *        `Reglas\EntranteNoBloqueado`'s own default
     *        (`BloqueoReemplazoPolicy::topeTresFechas()`); see README's
     *        "Contracts for slice 4" point 3 for why whoever eventually
     *        confirms CC5b must pass the real policy here explicitly rather
     *        than relying on this default forever.
     * @param bool                        $prioridadPadresActiva Forwarded
     *        verbatim to `DictamenEngineFactory::create()` — see
     *        `Reglas\PrioridadDePadresRespetada`'s docblock. Default `false`,
     *        mirroring `Calendario\Settings::prioridadPadresActiva()`'s own
     *        default.
     * @param bool                        $exencionArcoActiva Whether
     *        movement 1 of a grouped goalkeeper reassignment gets
     *        `DictamenContext::exencionArco() === true` in
     *        `evaluateGrupo()` — see that method's own docblock, and
     *        `Calendario\Settings::exencionArcoActiva()`'s docblock for the
     *        default (ON). Never consulted by `evaluate()` — an ordinary
     *        `sustitucion`/`regreso` always assembles with
     *        `exencionArco=false`, regardless of this value.
     */
    public function __construct(
        DictamenContextAssembler $assembler,
        EventLog $eventLog,
        ?BloqueoReemplazoPolicy $politicaCC5b = null,
        bool $prioridadPadresActiva = false,
        bool $exencionArcoActiva = true
    ) {
        $this->assembler             = $assembler;
        $this->eventLog              = $eventLog;
        $this->politicaCC5b          = $politicaCC5b;
        $this->prioridadPadresActiva = $prioridadPadresActiva;
        $this->exencionArcoActiva    = $exencionArcoActiva;
    }

    /**
     * @throws \Throwable Whatever `DictamenContextAssembler::assemble()`
     *         threw — logged first, then re-thrown as-is (see class
     *         docblock).
     */
    public function evaluate( SolicitudDeCambio $solicitud ): Dictamen {
        try {
            $ctx = $this->assembler->assemble( $solicitud );

            return DictamenEngineFactory::create( $this->politicaCC5b, $this->prioridadPadresActiva )->evaluate( $ctx );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'dictamen.fallido', [
                'season_id' => $solicitud->seasonId(),
                'team_id'   => $solicitud->teamId(),
                'plaza_id'  => $solicitud->plazaId(),
                'fecha_id'  => $solicitud->fechaId(),
                'tipo'      => $solicitud->tipo(),
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            throw $e;
        }
    }

    /**
     * Evaluates a grouped goalkeeper reassignment's two movements and
     * returns their UNIONED dictamen — see class docblock, "`evaluateGrupo()`
     * — THE GROUPED GOALKEEPER REASSIGNMENT".
     *
     * @param SolicitudDeCambio $legArco  Movement 1 — the goal plaza, built
     *        with `SolicitudDeCambio::sustitucion()` exactly like any other
     *        substitution (see that class's own docblock,
     *        `TIPO_REASIGNACION_ARQUERO`'s note). `entrantePlayerId()` is the
     *        field titular moving into goal.
     * @param SolicitudDeCambio $legCampo Movement 2 — the vacated field
     *        plaza, also built with `::sustitucion()`. `entrantePlayerId()`
     *        is the outside player filling it.
     * @throws \Throwable Whatever either `DictamenContextAssembler::assemble()`
     *         call threw — logged first (as `dictamen.grupo.fallido`), then
     *         re-thrown as-is, same discipline as `evaluate()`.
     */
    public function evaluateGrupo( SolicitudDeCambio $legArco, SolicitudDeCambio $legCampo ): Dictamen {
        try {
            $ctxArco  = $this->assembler->assemble( $legArco, $this->exencionArcoActiva );
            $ctxCampo = $this->assembler->assemble( $legCampo, false );

            $engine = DictamenEngineFactory::create( $this->politicaCC5b, $this->prioridadPadresActiva );

            $dictamenArco  = $engine->evaluate( $ctxArco );
            $dictamenCampo = $engine->evaluate( $ctxCampo );

            // Tagged BEFORE the union — see class docblock, "EACH MOTIVO IS
            // TAGGED WITH WHICH LEG PRODUCED IT" — so a codigo that can come
            // from either leg (e.g. `fuera_de_plazo`) is still attributable
            // once both lists are merged into one.
            $motivosArco  = array_map(
                static fn ( Motivo $motivo ): Motivo => $motivo->conMovimiento( 'arco' ),
                $dictamenArco->motivos()
            );
            $motivosCampo = array_map(
                static fn ( Motivo $motivo ): Motivo => $motivo->conMovimiento( 'campo' ),
                $dictamenCampo->motivos()
            );

            return Dictamen::from( array_merge( $motivosArco, $motivosCampo ) );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'dictamen.grupo.fallido', [
                'season_id'      => $legArco->seasonId(),
                'team_id'        => $legArco->teamId(),
                'plaza_arco_id'  => $legArco->plazaId(),
                'plaza_campo_id' => $legCampo->plazaId(),
                'fecha_id'       => $legArco->fechaId(),
                'excepcion'      => get_class( $e ),
                'mensaje'        => $e->getMessage(),
            ] );

            throw $e;
        }
    }
}
