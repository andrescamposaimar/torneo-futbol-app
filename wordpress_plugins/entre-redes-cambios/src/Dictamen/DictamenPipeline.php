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
 */
final class DictamenPipeline {

    private DictamenContextAssembler $assembler;
    private EventLog $eventLog;
    private ?BloqueoReemplazoPolicy $politicaCC5b;
    private bool $prioridadPadresActiva;

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
     */
    public function __construct(
        DictamenContextAssembler $assembler,
        EventLog $eventLog,
        ?BloqueoReemplazoPolicy $politicaCC5b = null,
        bool $prioridadPadresActiva = false
    ) {
        $this->assembler             = $assembler;
        $this->eventLog              = $eventLog;
        $this->politicaCC5b          = $politicaCC5b;
        $this->prioridadPadresActiva = $prioridadPadresActiva;
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
}
