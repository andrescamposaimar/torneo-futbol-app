<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Observability;

/**
 * The one channel every write and every failure in this plugin must speak
 * through. `$evento` is a stable, greppable code (e.g. `issuance.resolve_failed`,
 * `runtime.limits_low`, `approve.step_failed`) — never a free-text sentence.
 *
 * `$contexto` carries whatever ids and values make the event diagnosable —
 * player_id, request_id, attachment_id, and `$wpdb->last_error` when the
 * event is a failure. No shape is enforced here; each call site documents
 * what it passes.
 *
 * INJECTED, NEVER DEFAULTED — same convention as entre-redes-cambios's own
 * EventLog interface, copied here for the same reason: a default that
 * silently swallows events would reproduce the exact silent-failure pattern
 * this class exists to end.
 */
interface EventLog {

    /**
     * @param array<string, mixed> $contexto
     */
    public function record( string $evento, array $contexto ): void;
}
