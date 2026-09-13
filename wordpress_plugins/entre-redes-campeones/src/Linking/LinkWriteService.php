<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

use EntreRedes\Campeones\Titles\SquadRepository;

/**
 * Applies a link decision to a squad row — either the outcome of
 * LinkResolver::resolve() (auto/ambiguo/sin_candidato) or a human's explicit
 * choice (LINK-8, always `manual`, design §6).
 *
 * Both write paths go through the same SquadRepository::update(), so a
 * caller never has to duplicate the shape of a campeones_plantel write.
 */
final class LinkWriteService {

    public function __construct( private readonly SquadRepository $squads ) {
    }

    /**
     * Applies a LinkResolution produced by LinkResolver::resolve() — used by
     * the row editor's add/edit flow (LINK-1) and by RevalidationService
     * (LINK-9). Never called for a `manual` row: SquadRepository's
     * resolvable-only queries exclude it before this method is reached.
     */
    public function applyResolution( int $squadEntryId, LinkResolution $resolution ): bool {
        return $this->squads->update(
            $squadEntryId,
            [
                'jugador_id'      => $resolution->playerId,
                'estado_vinculo'  => $resolution->estado,
                'candidatos_json' => $this->encodeCandidateIds( $resolution->candidates ),
                'resolved_at'     => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * A human sets, changes, or clears a squad row's pointer (LINK-8). Any
     * of the three transitions the row to `manual` — including clearing it,
     * which leaves the pointer null but is still `manual`, so re-validation
     * (LINK-9/LINK-10) never touches it again.
     */
    public function setManualLink( int $squadEntryId, ?int $playerId ): bool {
        return $this->squads->update(
            $squadEntryId,
            [
                'jugador_id'      => $playerId,
                'estado_vinculo'  => LinkState::MANUAL,
                'candidatos_json' => null,
                'resolved_at'     => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * @param RegisteredPlayer[] $candidates
     */
    private function encodeCandidateIds( array $candidates ): ?string {
        if ( [] === $candidates ) {
            return null;
        }

        return json_encode( array_map( static fn ( RegisteredPlayer $c ): int => $c->id, $candidates ) ) ?: null; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
    }
}
