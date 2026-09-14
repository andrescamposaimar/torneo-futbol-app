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

        $succeeded         = 0;
        $directoryErrorIds = [];

        foreach ( $entries as $entry ) {
            try {
                $resolution = $this->resolver->resolve( $entry->jugadorNombre, $title->anio );
            } catch ( PlayerDirectoryQueryException $e ) {
                // Item 6: a broken directory read for THIS row must not
                // abort the rest of the year. Rows already written are
                // durable, and the remaining rows are independent
                // reads/writes with nothing to do with the one that just
                // failed — continue, and surface which row(s) broke instead
                // of losing visibility mid-loop.
                error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    'entre-redes-campeones: revalidation could not query the player directory for plantel_id=%d (titulo_id=%d). %s',
                    $entry->id,
                    $tituloId,
                    $e->getMessage()
                ) );
                $directoryErrorIds[] = $entry->id;
                continue;
            }

            if ( $this->writer->applyResolution( (int) $entry->id, $resolution ) ) {
                ++$succeeded;
            }
        }

        if ( [] !== $directoryErrorIds ) {
            // Item 3 (round 2): $directoryErrorRowIds was computed and
            // returned correctly, but nothing ever read it — a systemic
            // directory outage (every failure directory-side) rendered
            // identically to N rows with plain data-quality problems, with
            // no aggregate trace of which kind of failure actually happened.
            // One summary line here, in addition to the per-row lines
            // above, so an operator or a log search can tell "the directory
            // was down for this whole pass" apart from "these rows just
            // have bad names" without correlating dozens of per-row lines.
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: revalidation for titulo_id=%d: %d of %d attempted row(s) failed because the player directory was unreachable (plantel ids: %s).',
                $tituloId,
                count( $directoryErrorIds ),
                count( $entries ),
                implode( ',', $directoryErrorIds )
            ) );
        }

        return new RevalidationResult( $succeeded, count( $entries ), $directoryErrorIds );
    }
}
