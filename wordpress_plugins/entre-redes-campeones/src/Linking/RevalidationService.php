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
     * title. Returns the number of entries re-resolved.
     */
    public function revalidateYear( int $tituloId ): int {
        $title = $this->titles->find( $tituloId );
        if ( null === $title ) {
            return 0;
        }

        $entries = $this->squads->findResolvableByTitle( $tituloId );

        foreach ( $entries as $entry ) {
            $resolution = $this->resolver->resolve( $entry->jugadorNombre, $title->anio );
            $this->writer->applyResolution( (int) $entry->id, $resolution );
        }

        return count( $entries );
    }
}
