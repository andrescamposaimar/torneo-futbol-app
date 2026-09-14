<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Titles;

/**
 * Encapsulates all wpdb persistence for campeones_plantel.
 *
 * CRUD only in this slice — findByStates()/countByStates() (the aggregate
 * review queue query across all years, LINK-7 / ADMIN-8) land in slice 4
 * once the review queue exists to consume them.
 */
class SquadRepository {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Insert a new squad entry. estado_vinculo defaults to 'sin_candidato' —
     * every entry starts unlinked until LinkResolver (slice 2) or a human
     * (slice 3) sets it.
     *
     * @return int The new campeones_plantel.id.
     * @throws WriteFailedException When the INSERT fails at the database
     *                               level — the previous code discarded
     *                               wpdb::insert()'s return value entirely
     *                               and handed the caller a bogus id.
     */
    public function insert( SquadEntry $entry ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $inserted = $wpdb->insert(
            $p . 'campeones_plantel',
            [
                'titulo_id'           => $entry->tituloId,
                'orden'               => $entry->orden,
                'jugador_nombre'      => $entry->jugadorNombre,
                'jugador_nombre_norm' => $entry->jugadorNombreNorm,
                'jugador_id'          => $entry->jugadorId,
                'es_capitan'          => $entry->esCapitan ? 1 : 0,
                'estado_vinculo'      => $entry->estadoVinculo,
                'candidatos_json'     => $entry->candidatosJson,
            ]
        );

        if ( false === $inserted ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to insert campeones_plantel (titulo_id=%d, orden=%d, jugador_nombre=%s). DB error: %s',
                $entry->tituloId,
                $entry->orden,
                $entry->jugadorNombre,
                (string) $wpdb->last_error
            ) );
            throw new WriteFailedException( 'Failed to insert campeones_plantel record.' );
        }

        return (int) $wpdb->insert_id;
    }

    public function find( int $id ): ?SquadEntry {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}campeones_plantel WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return null === $row ? null : SquadEntry::fromRow( $row );
    }

    /**
     * Full squad for a title, ordered by orden ascending. Includes every
     * link state, including entries with no jugador_id — REC-6: a title
     * with zero linked squad entries is a valid, complete, displayable
     * record.
     *
     * @return SquadEntry[]
     */
    public function findByTitle( int $tituloId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}campeones_plantel
                  WHERE titulo_id = %d
                  ORDER BY orden ASC, id ASC",
                $tituloId
            ),
            ARRAY_A
        );

        return array_map( [ SquadEntry::class, 'fromRow' ], $rows ?: [] );
    }

    /**
     * Squad entries eligible for (re-)resolution — excludes `manual` rows at
     * the query level (LINK-10), not in a conditional after the fact, so a
     * bug in resolution logic can never see a human-set row in the first
     * place. RevalidationService (slice 3) is the only consumer.
     *
     * @return SquadEntry[]
     */
    public function findResolvableByTitle( int $tituloId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}campeones_plantel
                  WHERE titulo_id = %d AND estado_vinculo <> 'manual'
                  ORDER BY orden ASC, id ASC",
                $tituloId
            ),
            ARRAY_A
        );

        return array_map( [ SquadEntry::class, 'fromRow' ], $rows ?: [] );
    }

    /**
     * Update mutable fields of a squad entry (used by the row editor and by
     * link resolution). Only the fields the caller passes are updated.
     *
     * @param array<string, mixed> $data
     */
    public function update( int $id, array $data ): bool {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->update( $p . 'campeones_plantel', $data, [ 'id' => $id ] );

        if ( false === $result ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to update campeones_plantel (id=%d). DB error: %s',
                $id,
                (string) $wpdb->last_error
            ) );
        }

        return false !== $result;
    }

    public function delete( int $id ): bool {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->delete( $p . 'campeones_plantel', [ 'id' => $id ] );

        if ( false === $result ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to delete campeones_plantel (id=%d). DB error: %s',
                $id,
                (string) $wpdb->last_error
            ) );
        }

        return false !== $result;
    }
}
