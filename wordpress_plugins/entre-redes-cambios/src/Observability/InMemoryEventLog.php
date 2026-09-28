<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Observability;

/**
 * Test double for EventLog: keeps every recorded event in memory, in order,
 * so a test can assert not just THAT an event was recorded, but the ORDER
 * events happened in — e.g. that a failure event exists BEFORE the exception
 * that propagates from the same call (see this slice's task description:
 * "verificá el orden: el evento existe aunque la excepción se propague").
 */
class InMemoryEventLog implements EventLog {

    /** @var array<int, array{evento: string, contexto: array<string, mixed>}> */
    private array $events = [];

    public function record( string $evento, array $contexto ): void {
        $this->events[] = [
            'evento'   => $evento,
            'contexto' => $contexto,
        ];
    }

    /**
     * @return array<int, array{evento: string, contexto: array<string, mixed>}>
     */
    public function all(): array {
        return $this->events;
    }

    public function has( string $evento ): bool {
        foreach ( $this->events as $event ) {
            if ( $event['evento'] === $evento ) {
                return true;
            }
        }

        return false;
    }

    public function count(): int {
        return count( $this->events );
    }

    /**
     * The last recorded event, or null when nothing was recorded yet — the
     * common case a test needs: "the last thing that happened was this
     * failure event, right before the exception propagated".
     *
     * @return array{evento: string, contexto: array<string, mixed>}|null
     */
    public function last(): ?array {
        if ( empty( $this->events ) ) {
            return null;
        }

        return $this->events[ count( $this->events ) - 1 ];
    }
}
