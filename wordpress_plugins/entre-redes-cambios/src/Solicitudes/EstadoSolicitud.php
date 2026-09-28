<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes;

use EntreRedes\Cambios\Solicitudes\Exception\TransicionInvalidaException;

/**
 * THE single source of truth for `cambios_solicitud.estado` and for which
 * transitions between states are legal — see this slice's task description:
 * "nada de comparar strings sueltos por ahí". Every caller that needs to
 * move a solicitud from one estado to another (`SolicitudRepository::aprobar()`,
 * `::rechazar()`, `::anular()`, `::publicarLote()`) asks THIS class first,
 * exactly like `Dictamen\DictamenEngineFactory` is the one place allowed to
 * know the full ruleset — the same shape of bug (a caller re-deriving its
 * own copy of "what is a valid transition" and silently drifting from every
 * other caller) is what a single enumeration here closes off.
 *
 * *** WHY THIS IS NOT A DB CONSTRAINT ***
 * Same reasoning as `Calendario\FechaRepository::VALID_ESTADOS` and
 * `Plazas\PlazaRepository::VALID_TIPOS`: `cambios_solicitud.estado` is
 * declared as an `ENUM` in `Migrations\InitialSchema` for readability in a
 * real MySQL schema, but the SQLite test shim rewrites every `ENUM` column to
 * `TEXT` (see that class's own docblock), and even a real, non-strict MySQL
 * silently truncates an out-of-range `ENUM` value instead of rejecting it. A
 * transition graph is something no `ENUM` could express anyway — MySQL has no
 * notion of "this column may only move from A to B, never from C to B" — so
 * this whole discipline lives in code, defended as a PROPERTY
 * (`EstadoSolicitudTest` asserts every valid AND every invalid pair), never
 * as a schema constraint.
 *
 * *** THE GRAPH, AND WHY IT SHAPES THE REAL CALENDAR ***
 * `pendiente` is where every solicitud starts. From there the process owner
 * can `aprobar()`, `rechazar()`, or `anular()` it — but APROBAR IS NOT THE
 * SAME THING AS PUBLICAR: the reglamento's calendar (see this plugin's
 * README, "Solicitudes de cambio: ciclo de vida") has the process owner
 * approving solicitudes all through Wednesday and Thursday as they arrive,
 * while the change only becomes official at Friday's lote announcement. So
 * `aprobada` is itself not terminal — it can still move to `publicada` (the
 * Friday lote), or the process owner can change their mind before then and
 * move it to `rechazada` or `anulada`. `publicada`, `rechazada` and
 * `anulada` are the only terminal states: nothing transitions out of any of
 * them.
 */
final class EstadoSolicitud {

    public const PENDIENTE = 'pendiente';
    public const APROBADA  = 'aprobada';
    public const RECHAZADA = 'rechazada';
    public const PUBLICADA = 'publicada';
    public const ANULADA   = 'anulada';

    /**
     * Every valid state, in no particular order — used to validate a raw
     * `estado` string read back from the database before this class is
     * asked anything about it.
     *
     * @var string[]
     */
    private const TODOS = [ self::PENDIENTE, self::APROBADA, self::RECHAZADA, self::PUBLICADA, self::ANULADA ];

    /**
     * THE transition graph. Keys are the states a solicitud is currently in;
     * values are every state it may legally move to from there. A state
     * absent as a key (`publicada`, `rechazada`, `anulada`) is terminal —
     * see `esTerminal()`.
     *
     * @var array<string, string[]>
     */
    private const TRANSICIONES = [
        self::PENDIENTE => [ self::APROBADA, self::RECHAZADA, self::ANULADA ],
        self::APROBADA  => [ self::PUBLICADA, self::RECHAZADA, self::ANULADA ],
    ];

    /**
     * Not instantiable — this class is a namespace for static state-machine
     * logic, the same shape as `Dictamen\DictamenEngineFactory`.
     */
    private function __construct() {
    }

    /** @return string[] Every valid `cambios_solicitud.estado` value. */
    public static function todos(): array {
        return self::TODOS;
    }

    public static function esValido( string $estado ): bool {
        return in_array( $estado, self::TODOS, true );
    }

    /**
     * True for `publicada`, `rechazada` and `anulada` — a solicitud in any
     * of these states never moves again.
     */
    public static function esTerminal( string $estado ): bool {
        return ! array_key_exists( $estado, self::TRANSICIONES );
    }

    /**
     * @return string[] Every state $desde may legally move to — empty for a
     *         terminal state, and also empty (rather than throwing) for an
     *         unrecognized $desde, since a caller asking "what can I move to
     *         FROM an invalid state" is answered honestly with "nothing",
     *         not with a crash; `assertTransicionValida()` is what actually
     *         enforces $desde being valid in the first place.
     */
    public static function transicionesDesde( string $desde ): array {
        return self::TRANSICIONES[ $desde ] ?? [];
    }

    public static function esTransicionValida( string $desde, string $hasta ): bool {
        return in_array( $hasta, self::transicionesDesde( $desde ), true );
    }

    /**
     * @throws TransicionInvalidaException When $desde does not recognize
     *         $hasta as one of its legal next states — covers $desde being
     *         terminal, $desde being an unrecognized string, and $hasta
     *         being unrecognized, all with one check: none of those ever
     *         appear in `transicionesDesde($desde)`.
     */
    public static function assertTransicionValida( string $desde, string $hasta ): void {
        if ( self::esTransicionValida( $desde, $hasta ) ) {
            return;
        }

        throw new TransicionInvalidaException( $desde, $hasta );
    }
}
