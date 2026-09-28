<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania;

use EntreRedes\Cambios\Capitania\Exception\CapitanPersistenceException;

/**
 * Encapsulates all wpdb persistence for cambios_capitan.
 *
 * See Migrations\InitialSchema::sqlCambiosCapitan() for the full identity
 * and history model this class defends: at most one VIGENT
 * (`revocado_at IS NULL`) row per `(season_id, team_id)`, enforced by a
 * SELECT-then-insert/revoke guard in designateCapitan() — never by a UNIQUE key, for
 * the reasons documented there (the table is also the audit history, and
 * MySQL treats every NULL in a UNIQUE index as distinct from every other
 * NULL). The SQLite test shim also drops every KEY/INDEX line from the
 * schema (see InitialSchema's class docblock), so nothing here could rely on
 * a declarative constraint even if one existed — CapitanRepositoryTest
 * asserts the PROPERTY ("never two vigent rows for the same pair"), not a
 * constraint violation.
 *
 * NOTE ON THE `capitan` TAXONOMY TERM: `sp_position` term id 52 ("Capitan")
 * is a POSITION TAG on a player — like Arquero or Defensor — with no team,
 * no temporada, and no authority attached. It is what `/jugadores`'s
 * `capitan: true` flag reflects. This class and everything built on it
 * (Capitania\CapitanAuthorizer) NEVER read that flag; the only source of truth
 * for "who can act as captain of this team, this season" is a VIGENT row in
 * cambios_capitan, designated explicitly through designateCapitan(). Do not
 * wire the taxonomy term into authorization later — it means something else
 * entirely.
 */
class CapitanRepository {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Designates $playerId as captain of ($seasonId, $teamId), revoking
     * whatever designation was previously vigent for that pair — the revoke
     * and the insert happen inside one transaction, so a caller can never
     * observe a moment with zero or two vigent captains for the same team.
     *
     * IDEMPOTENT: designating the SAME player_id that is already the vigent
     * captain of this team is a no-op — no new row is created and the
     * existing one is not revoked — and returns that row's existing id.
     *
     * Not safe against two concurrent designateCapitan() calls for the same
     * team racing past the initial findCapitanVigente() read before either's
     * transaction starts — the same class of gap
     * Calendario\FechaRepository::nextFreeOrden() accepts for its
     * single-operator seeding path. Today captains are designated by a
     * single operator (WP-CLI/admin), never by concurrent requests; revisit
     * if a later slice exposes this over a REST endpoint multiple clients
     * could hit at once.
     *
     * @return int The id of the vigent cambios_capitan row after this call —
     *         a freshly inserted row on a change of captain, or the existing
     *         row's id when re-designating the incumbent.
     *
     * @throws CapitanPersistenceException When the revoke of the previous
     *         captain, or the insert of the new one, fails at the wpdb
     *         level ($wpdb->insert()/update() returning `false` instead of
     *         throwing) — thrown BEFORE the COMMIT so the transaction rolls
     *         back instead of persisting a partial write.
     */
    public function designateCapitan( int $seasonId, int $teamId, int $playerId, ?int $designadoPor, string $now ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $vigente = $this->findCapitanVigente( $seasonId, $teamId );

        if ( null !== $vigente && (int) $vigente['player_id'] === $playerId ) {
            return (int) $vigente['id'];
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            if ( null !== $vigente ) {
                $this->revokeRow( (int) $vigente['id'], $now );
            }

            $result = $wpdb->insert(
                $p . 'cambios_capitan',
                [
                    'season_id'     => $seasonId,
                    'team_id'       => $teamId,
                    'player_id'     => $playerId,
                    'designado_por' => $designadoPor,
                    'designado_at'  => $now,
                    'revocado_at'   => null,
                ]
            );

            if ( false === $result ) {
                throw new CapitanPersistenceException( 'insert', $wpdb->last_error );
            }

            $newId = (int) $wpdb->insert_id;

            if ( $newId <= 0 ) {
                throw new CapitanPersistenceException( 'insert', $wpdb->last_error );
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        return $newId;
    }

    /**
     * Revokes the vigent captaincy of ($seasonId, $teamId), if any.
     *
     * @return bool false when there was nothing vigent to revoke — a team
     *         without a captain is a valid state, not an error.
     *
     * @throws CapitanPersistenceException When the underlying wpdb update
     *         fails (see revokeRow()).
     */
    public function revokeCapitan( int $seasonId, int $teamId, string $now ): bool {
        $vigente = $this->findCapitanVigente( $seasonId, $teamId );

        if ( null === $vigente ) {
            return false;
        }

        $this->revokeRow( (int) $vigente['id'], $now );

        return true;
    }

    /**
     * @return array<string, mixed>|null The vigent (`revocado_at IS NULL`)
     *         row for ($seasonId, $teamId), or null when the team currently
     *         has no captain.
     */
    public function findCapitanVigente( int $seasonId, int $teamId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_capitan
                  WHERE season_id = %d AND team_id = %d AND revocado_at IS NULL
                  LIMIT 1",
                $seasonId,
                $teamId
            ),
            ARRAY_A
        );

        return empty( $row ) ? null : $row;
    }

    public function isCapitanVigente( int $seasonId, int $teamId, int $playerId ): bool {
        $vigente = $this->findCapitanVigente( $seasonId, $teamId );

        return null !== $vigente && (int) $vigente['player_id'] === $playerId;
    }

    /**
     * @return array<int, int> team_id's this player currently captains in
     *         this season — normally zero or one, but this method does not
     *         artificially enforce that ceiling: nothing in this schema
     *         stops the same person from captaining more than one team at
     *         once, and hiding that here would just move the surprise
     *         somewhere else.
     */
    public function listEquiposByCapitan( int $seasonId, int $playerId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT team_id FROM {$p}cambios_capitan
                  WHERE season_id = %d AND player_id = %d AND revocado_at IS NULL",
                $seasonId,
                $playerId
            ),
            ARRAY_A
        );

        return array_map( static fn( array $r ): int => (int) $r['team_id'], $rows ?: [] );
    }

    /**
     * @throws CapitanPersistenceException When $wpdb->update() returns
     *         `false` (see wpdb's own contract — it never throws on
     *         failure) instead of the number of affected rows.
     */
    private function revokeRow( int $id, string $now ): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->update( $p . 'cambios_capitan', [ 'revocado_at' => $now ], [ 'id' => $id ] );

        if ( false === $result ) {
            throw new CapitanPersistenceException( 'revoke', $wpdb->last_error );
        }
    }
}
