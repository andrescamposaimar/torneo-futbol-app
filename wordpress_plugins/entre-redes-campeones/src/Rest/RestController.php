<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

/**
 * Registers all /entre-redes/v1/campeones/* REST routes (design §7). Follows
 * entre-redes-prode's RestController nullable-slot aggregator pattern: each
 * slot is optional so a future controller can be wired in without touching
 * every existing call site, and a missing slot simply registers no routes
 * for it instead of failing.
 */
final class RestController {

    public function __construct(
        private readonly ?HistoryController $history = null,
        private readonly ?PlayerTitlesController $playerTitles = null
    ) {
    }

    public function register_routes(): void {
        if ( null !== $this->history ) {
            $this->history->register_routes();
        }

        if ( null !== $this->playerTitles ) {
            $this->playerTitles->register_routes();
        }
    }
}
