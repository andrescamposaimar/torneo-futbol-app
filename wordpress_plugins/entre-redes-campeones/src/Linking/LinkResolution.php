<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * The outcome of LinkResolver::resolve() (design §3).
 */
final class LinkResolution {

    /**
     * @param RegisteredPlayer[] $candidates Retained candidates when
     *                                       estado is LinkState::AMBIGUO;
     *                                       empty otherwise.
     */
    public function __construct(
        public readonly ?int $playerId,
        public readonly string $estado,
        public readonly array $candidates = []
    ) {
    }
}
