<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Observability;

/**
 * The one channel every write and every failure in this plugin must speak
 * through — see this slice's task description for why: before this existed,
 * the plugin had no `error_log()` call and no hook anywhere, so "a captain
 * says a change request did nothing" had literally nothing to look at.
 *
 * `$evento` is a stable, greppable code (e.g. `plaza.abierta`,
 * `ocupacion.sucedida`, `escritura.fallida`) — never a free-text sentence.
 * Whoever ends up reading a production log greps for these codes, so a
 * caller must never invent a new one inline without adding it here as a
 * documented convention (see WpEventLog and InMemoryEventLog's callers —
 * Plazas\PlazaRepository and Capitania\CapitanRepository — for the full
 * list currently in use).
 *
 * `$contexto` carries whatever ids and values make the event diagnosable —
 * season_id, team_id, plaza_id, ocupacion_id, player_id, fecha_id, and
 * `$wpdb->last_error` when the event is a failure. No shape is enforced
 * here; each call site documents what it passes.
 *
 * INJECTED, NEVER DEFAULTED: Plazas\PlazaRepository and
 * Capitania\CapitanRepository both require an EventLog in their constructor
 * with no null-object fallback. A default that silently swallows events
 * would reproduce, in code, the exact silent-failure pattern this class
 * exists to end — see those classes' constructors for the same rule spelled
 * out again at the call site.
 */
interface EventLog {

    /**
     * @param array<string, mixed> $contexto
     */
    public function record( string $evento, array $contexto ): void;
}
