<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Observability;

/**
 * Test double for EventLog: keeps every recorded event in memory, in order.
 * Copied from entre-redes-cambios/src/Observability/InMemoryEventLog.php.
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
     * @return array{evento: string, contexto: array<string, mixed>}|null
     */
    public function last(): ?array {
        if ( empty( $this->events ) ) {
            return null;
        }

        return $this->events[ count( $this->events ) - 1 ];
    }
}
