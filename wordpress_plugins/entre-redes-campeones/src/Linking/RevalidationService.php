<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;

/**
 * On-demand, per-year re-validation (ADMIN-9, LINK-9). Consumes
 * SquadRepository::findResolvableByTitle() ONLY, so a `manual` row is
 * excluded at the query level (LINK-10) — this service never even loads
 * one, let alone overwrites it.
 *
 * Reachable from nothing but an explicit form submit: this class registers
 * no hook, is invoked by no other hook, and is never scheduled — see
 * tests/PluginNoCronTest.php for the plugin-wide guarantee that there is no
 * cron layer to accidentally wire this into.
 */
final class RevalidationService {

    public function __construct(
        private readonly TitleRepository $titles,
        private readonly SquadRepository $squads,
        private readonly LinkResolver $resolver,
        private readonly LinkWriteService $writer
    ) {
    }

    /**
     * Re-runs LINK-1 through LINK-6 for every non-`manual` entry of one
     * title. Returns BOTH how many entries were ACTUALLY re-resolved — i.e.
     * whose write succeeded — and how many were attempted in total (item 2).
     * A bare succeeded count let row 14 of 25 failing report the same `1`
     * that a genuine 1-of-1 success would, with nothing to tell the two
     * apart; the caller needs both numbers to render an honest notice.
     * Counting real successes (rather than making the whole pass
     * transactional) matches every other write path in this class's own
     * dependencies — a squad row is always written and reported one at a
     * time, never batched — and lets an operator retry just the rows that
     * actually failed instead of every row in the year.
     */
    public function revalidateYear( int $tituloId ): RevalidationResult {
        $title = $this->titles->find( $tituloId );
        if ( null === $title ) {
            return new RevalidationResult( 0, 0 );
        }

        $entries = $this->squads->findResolvableByTitle( $tituloId );

        $succeeded = 0;
        foreach ( $entries as $entry ) {
            $resolution = $this->resolver->resolve( $entry->jugadorNombre, $title->anio );
            if ( $this->writer->applyResolution( (int) $entry->id, $resolution ) ) {
                ++$succeeded;
            }
        }

        return new RevalidationResult( $succeeded, count( $entries ) );
    }
}
