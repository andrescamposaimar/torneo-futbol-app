<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Titles;

/**
 * Deletes a title and its full squad inside one transaction (ADMIN-7's
 * "Eliminar" action on the Titles list page). Squad rows are deleted
 * first, then the title row — mirroring the delete-then-insert ordering
 * ImportService uses for a confirmed replace (design §5), so a title never
 * ends up with orphaned squad rows and a squad row never points at a
 * title that no longer exists.
 *
 * Both writes are checked; the first failure rolls back and returns false,
 * leaving both tables exactly as they were.
 */
final class TitleDeletionService {

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly TitleRepository $titles,
        private readonly SquadRepository $squads
    ) {
    }

    public function delete( int $tituloId ): bool {
        $wpdb = $this->wpdb;

        $wpdb->query( 'START TRANSACTION' );

        if ( ! $this->squads->deleteByTitle( $tituloId ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        if ( ! $this->titles->delete( $tituloId ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $wpdb->query( 'COMMIT' );
        return true;
    }
}
