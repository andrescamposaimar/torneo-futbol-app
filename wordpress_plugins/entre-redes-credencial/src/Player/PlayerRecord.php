<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Player;

/**
 * The facts PlayerReader::resolve() needs downstream (Credencial\CredencialService)
 * to decide eligibility and shape the GET response — a value object, not an
 * array, so every reader of "is this player blocked" / "what is their
 * caracter" goes through the SAME PlayerReader classification (design D14).
 */
final class PlayerRecord {

    public function __construct(
        private readonly int $playerId,
        private readonly string $fullName,
        private readonly string $dni,
        private readonly ?string $caracter,
        private readonly ?string $birthDate,
        private readonly bool $blocked
    ) {
    }

    public function playerId(): int {
        return $this->playerId;
    }

    public function fullName(): string {
        return $this->fullName;
    }

    public function dni(): string {
        return $this->dni;
    }

    public function caracterOrNull(): ?string {
        return $this->caracter;
    }

    /** @return string|null `Y-m-d`, or null when unresolvable/outside [18, 100] years. */
    public function birthDateOrNull(): ?string {
        return $this->birthDate;
    }

    public function isBlocked(): bool {
        return $this->blocked;
    }
}
