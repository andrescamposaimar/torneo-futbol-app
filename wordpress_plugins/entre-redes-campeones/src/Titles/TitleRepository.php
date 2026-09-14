<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Titles;

/**
 * Encapsulates all wpdb persistence for campeones_titulo.
 *
 * Conflict detection (REC-7) is enforced entirely in application code: the
 * SQLite test shim drops every UNIQUE KEY / KEY line from CREATE TABLE
 * (design §2), so no test can ever observe a DB-level uniqueness violation.
 * createOrConflict() SELECTs by (anio, zona, posicion) before INSERTing,
 * inside START TRANSACTION — the entre-redes-prode
 * PredictionRepository::upsert() pattern (ADR-G2-2) — never
 * INSERT ... ON DUPLICATE KEY UPDATE (REC-9, TDD-2).
 */
class TitleRepository {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    public function find( int $id ): ?TitleRecord {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, anio, zona, posicion, equipo_nombre, created_at, updated_at
                   FROM {$p}campeones_titulo
                  WHERE id = %d
                  LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return null === $row ? null : TitleRecord::fromRow( $row );
    }

    public function findByKey( int $anio, string $zona, string $posicion ): ?TitleRecord {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, anio, zona, posicion, equipo_nombre, created_at, updated_at
                   FROM {$p}campeones_titulo
                  WHERE anio = %d AND zona = %s AND posicion = %s
                  LIMIT 1",
                $anio,
                $zona,
                $posicion
            ),
            ARRAY_A
        );

        return null === $row ? null : TitleRecord::fromRow( $row );
    }

    /**
     * Insert a new title record, rejecting a duplicate (anio, zona,
     * posicion) in application code (REC-7). The existence check and the
     * insert run inside the same transaction so this repository's own
     * callers see them as atomic.
     *
     * @return TitleRecord|null The newly created record, or null when a
     *                          record already exists for this key — in
     *                          which case the existing record is left
     *                          completely unchanged.
     * @throws WriteFailedException When the INSERT itself fails at the
     *                               database level. Deliberately distinct
     *                               from the null return above: null means
     *                               "a duplicate already exists" (REC-7), an
     *                               exception means "the write was lost" —
     *                               a caller must never confuse the two.
     */
    public function createOrConflict( int $anio, string $zona, string $posicion, string $equipoNombre ): ?TitleRecord {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;
        $now  = current_time( 'mysql' );

        $wpdb->query( 'START TRANSACTION' );

        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$p}campeones_titulo
                  WHERE anio = %d AND zona = %s AND posicion = %s
                  LIMIT 1",
                $anio,
                $zona,
                $posicion
            )
        );

        if ( null !== $existingId ) {
            $wpdb->query( 'ROLLBACK' );
            return null;
        }

        $inserted = $wpdb->insert(
            $p . 'campeones_titulo',
            [
                'anio'          => $anio,
                'zona'          => $zona,
                'posicion'      => $posicion,
                'equipo_nombre' => $equipoNombre,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to insert campeones_titulo (anio=%d, zona=%s, posicion=%s). DB error: %s',
                $anio,
                $zona,
                $posicion,
                (string) $wpdb->last_error
            ) );
            throw new WriteFailedException( 'Failed to insert campeones_titulo record.' );
        }

        $newId = (int) $wpdb->insert_id;

        $wpdb->query( 'COMMIT' );

        return $this->find( $newId );
    }
}
