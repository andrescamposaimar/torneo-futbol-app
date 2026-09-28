<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * One candidate's shape, as CandidatosResolver::paraPlaza() reports it: who
 * they are, whether they are a padre, their puntaje (if resolvable), whether
 * they are VIABLE for the plaza being asked about, and — when they are not —
 * why. See CandidatosResolver's class docblock for what "viable" means and
 * why it is decided there, once, rather than by each of this value object's
 * two consumers (Dictamen\Reglas\PrioridadDePadresRespetada and the captain's
 * candidatos endpoint).
 */
final class CandidatoEstado {

    private int $playerId;
    private bool $esPadre;
    private ?Puntaje $puntaje;
    private bool $viable;
    private ?string $motivoNoViable;

    public function __construct(
        int $playerId,
        bool $esPadre,
        ?Puntaje $puntaje,
        bool $viable,
        ?string $motivoNoViable
    ) {
        $this->playerId       = $playerId;
        $this->esPadre        = $esPadre;
        $this->puntaje        = $puntaje;
        $this->viable         = $viable;
        $this->motivoNoViable = $motivoNoViable;
    }

    public function playerId(): int {
        return $this->playerId;
    }

    public function esPadre(): bool {
        return $this->esPadre;
    }

    public function puntaje(): ?Puntaje {
        return $this->puntaje;
    }

    public function viable(): bool {
        return $this->viable;
    }

    /**
     * Null exactly when viable() is true — a viable candidate has nothing to
     * explain.
     */
    public function motivoNoViable(): ?string {
        return $this->motivoNoViable;
    }
}
