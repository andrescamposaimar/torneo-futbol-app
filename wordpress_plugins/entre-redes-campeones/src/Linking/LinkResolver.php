<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * Orchestrates the pure matching parts plus the directory seam
 * (design §3). LINK-10 is enforced by the caller's query
 * (SquadRepository::findResolvableByTitle), not here — this resolver is
 * never invoked for a `manual` row in the first place.
 */
final class LinkResolver {

    /**
     * The first year sp_season data exists for (verified: terms exist
     * from 2016 on, none for 2009-2015). Below this year, season
     * narrowing is skipped entirely (LINK-3) — a wider candidate set is
     * accepted behaviour, not an error.
     */
    private const SEASON_FILTER_MIN_YEAR = 2016;

    public function __construct( private readonly PlayerDirectoryInterface $directory ) {
    }

    public function resolve( string $jugadorNombre, int $anio ): LinkResolution {
        $entryKey = NameParser::keyFor( $jugadorNombre );
        if ( null === $entryKey ) {
            return new LinkResolution( null, LinkState::SIN_CANDIDATO, [] );
        }

        $candidates = $this->directory->findBySurname( $entryKey->surname );
        $candidates = array_values(
            array_filter(
                $candidates,
                static fn ( RegisteredPlayer $candidate ): bool =>
                    null !== $candidate->key && CandidateMatcher::matches( $entryKey, $candidate->key )
            )
        );

        if ( $anio >= self::SEASON_FILTER_MIN_YEAR ) {
            $candidates = array_values(
                array_filter(
                    $candidates,
                    static function ( RegisteredPlayer $candidate ) use ( $anio ): bool {
                        foreach ( $candidate->seasonNames as $seasonName ) {
                            if ( SeasonYearFilter::seasonCoversYear( $seasonName, $anio ) ) {
                                return true;
                            }
                        }
                        return false;
                    }
                )
            );
        }

        $count = count( $candidates );

        if ( 1 === $count ) {
            return new LinkResolution( $candidates[0]->id, LinkState::AUTO, [] );
        }

        if ( $count >= 2 ) {
            return new LinkResolution( null, LinkState::AMBIGUO, $candidates );
        }

        return new LinkResolution( null, LinkState::SIN_CANDIDATO, [] );
    }
}
