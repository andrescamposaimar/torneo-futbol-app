<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Plazas\Exception\PlazaPersistenceException;

/**
 * Encapsulates all wpdb persistence for cambios_plaza + cambios_ocupacion.
 *
 * THE AGGREGATE IS THE PLAZA, NOT THE SOLICITUD — see Plazas\CadenaResolver's
 * class docblock for the full domain model this class persists. In short: a
 * plaza is created once (openPlaza(), the "conformación" of March) together
 * with its genesis ocupación (the titular occupying it from day one), and
 * from then on every change of occupant is a NEW LINK appended to the same
 * chain (succeedOcupacion() / closeOcupacionByRegresoTitular()) — never a
 * mutation of the plaza itself, except for its bookkeeping columns.
 *
 * *** THE INVARIANT THIS CLASS DEFENDS ***
 * A plaza has AT MOST ONE VIGENT ocupación (`fecha_hasta_id IS NULL`) at any
 * time. Exactly like Capitania\CapitanRepository::designateCapitan() defends
 * "at most one vigent captain per team" — see that class's docblock for why
 * this cannot be a UNIQUE key (the table is also the audit history, and MySQL
 * treats every NULL in a UNIQUE index as distinct from every other NULL) —
 * every method that closes or opens an ocupación does so inside a single
 * transaction: close-then-insert (or insert-then-close) never happens as two
 * independent statements a caller could observe half-applied. The SQLite test
 * shim used in PHPUnit also drops every KEY/UNIQUE KEY line from the schema
 * (see Migrations\InitialSchema's class docblock), so — same as
 * CapitanRepository — PlazaRepositoryTest asserts this as a PROPERTY ("never
 * two vigent ocupaciones for the same plaza"), never as a DB constraint
 * violation.
 *
 * $wpdb->insert()/update() do NOT throw on failure — they return `false` (see
 * wpdb's own contract, replicated by the SQLite test shim). Every write
 * inside a transaction below checks that return value and throws
 * PlazaPersistenceException BEFORE the COMMIT, exactly like
 * CapitanRepository — the same class of blocker fixed there (a failed second
 * write silently falling through to COMMIT) applies here just as much.
 *
 * TWO CONCURRENT CALLERS CAN RACE ACROSS TRANSACTIONS, NOT JUST WITHIN ONE:
 * the paragraph above covers atomicity WITHIN a single succeedOcupacion() /
 * closeOcupacionByRegresoTitular() call. It does not by itself stop two
 * SEPARATE calls (two different requests) from both reading the same vigent
 * ocupación via findOcupacionVigente() before either closes it — a plain
 * `UPDATE ... WHERE id = %d` would let both "succeed" (the loser's UPDATE
 * matches the row unconditionally, even though it was already closed by the
 * winner), producing two vigent ocupaciones. closeOcupacion() closes this gap
 * with a compare-and-swap: the UPDATE's WHERE also requires
 * `fecha_hasta_id IS NULL`, and the caller requires EXACTLY 1 affected row —
 * see that method's docblock. findOcupacionVigente() no longer trusts
 * `LIMIT 1` either: it throws if it ever finds more than one vigent row,
 * because a caller's business decision must never depend on row storage
 * order when the invariant is supposed to guarantee at most one.
 */
class PlazaRepository {

    /**
     * The only 2 values `cambios_plaza.tipo` may ever hold. See
     * Calendario\FechaRepository::VALID_ESTADOS's docblock for why an
     * ENUM-backed column still needs a code-level whitelist: non-strict MySQL
     * silently truncates an out-of-range ENUM value, and the SQLite test shim
     * rewrites every ENUM column to TEXT, so nothing but this whitelist
     * actually guards `tipo` in tests.
     */
    private const VALID_TIPOS = [ 'campo', 'suplente' ];

    /**
     * The only 3 values `cambios_ocupacion.cerrada_por` may ever hold — see
     * this table's docblock in Migrations\InitialSchema for what each one
     * means. Same ENUM-is-not-a-guard rationale as VALID_TIPOS above.
     */
    private const VALID_CERRADA_POR = [ 'regreso_titular', 'reemplazada', 'trunca' ];

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Opens a brand-new plaza AND its genesis ocupación (the titular,
     * `es_genesis = 1`, occupying it from `$fechaDesdeId`) in one
     * transaction — this is the "conformación" moment described in the
     * class docblock. `$puntajeTecho` is snapshotted once, here, and never
     * moves again for the lifetime of the plaza — see
     * Migrations\InitialSchema::sqlCambiosPlaza()'s docblock.
     *
     * @throws \InvalidArgumentException When $tipo is not one of
     *         self::VALID_TIPOS.
     * @throws PlazaPersistenceException When either insert fails at the wpdb
     *         level — thrown BEFORE the COMMIT, so the transaction rolls
     *         back instead of persisting a plaza with no ocupación (or vice
     *         versa).
     */
    public function openPlaza(
        int $seasonId,
        int $teamId,
        int $titularPlayerId,
        Puntaje $puntajeTecho,
        string $tipo,
        int $fechaDesdeId,
        string $now
    ): int {
        if ( ! in_array( $tipo, self::VALID_TIPOS, true ) ) {
            throw new \InvalidArgumentException(
                "PlazaRepository::openPlaza(): '{$tipo}' is not a valid tipo. "
                . 'Valid values are: ' . implode( ', ', self::VALID_TIPOS ) . '.'
            );
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $plazaResult = $wpdb->insert(
                $p . 'cambios_plaza',
                [
                    'season_id'         => $seasonId,
                    'team_id'           => $teamId,
                    'titular_player_id' => $titularPlayerId,
                    'puntaje_techo'     => $puntajeTecho->halfPoints(),
                    'tipo'              => $tipo,
                    'created_at'        => $now,
                    'closed_at'         => null,
                ]
            );

            if ( false === $plazaResult ) {
                throw new PlazaPersistenceException( 'insert cambios_plaza', $wpdb->last_error );
            }

            $plazaId = (int) $wpdb->insert_id;

            if ( $plazaId <= 0 ) {
                throw new PlazaPersistenceException( 'insert cambios_plaza', $wpdb->last_error );
            }

            $this->insertOcupacion(
                $plazaId,
                $titularPlayerId,
                true,
                $fechaDesdeId,
                $now
            );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        return $plazaId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlaza( int $plazaId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_plaza WHERE id = %d LIMIT 1",
                $plazaId
            ),
            ARRAY_A
        );

        return empty( $row ) ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlazasByEquipo( int $seasonId, int $teamId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_plaza
                  WHERE season_id = %d AND team_id = %d
                  ORDER BY id ASC",
                $seasonId,
                $teamId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * The full chain of ocupaciones for a plaza, ordered chronologically by
     * `fecha_desde_id` (tie-broken by `id` for determinism when two links
     * share the same starting fecha, which should not happen but costs
     * nothing to order deterministically anyway).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOcupaciones( int $plazaId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_ocupacion
                  WHERE plaza_id = %d
                  ORDER BY fecha_desde_id ASC, id ASC",
                $plazaId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * @return array<string, mixed>|null The vigent (`fecha_hasta_id IS
     *         NULL`) ocupación of this plaza, or null when the plaza somehow
     *         has none open — should not happen once openPlaza() has run,
     *         but callers should not assume it.
     *
     * @throws \RuntimeException When MORE THAN ONE ocupación of this plaza is
     *         vigent at once — this is a violation of the invariant this
     *         class defends (see class docblock) and must never be silently
     *         resolved by picking one at random via `LIMIT 1`. A caller's
     *         business decision (who occupies a plaza right now) must never
     *         depend on row storage order. If this ever fires, the fix is to
     *         repair the corrupted `cambios_ocupacion` data for this
     *         `plaza_id`, not to add `LIMIT 1` back.
     */
    public function findOcupacionVigente( int $plazaId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_ocupacion
                  WHERE plaza_id = %d AND fecha_hasta_id IS NULL",
                $plazaId
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return null;
        }

        if ( count( $rows ) > 1 ) {
            throw new \RuntimeException(
                "PlazaRepository::findOcupacionVigente(): plaza {$plazaId} has "
                . count( $rows ) . ' vigent ocupaciones — invariant broken, expected at most 1.'
            );
        }

        return $rows[0];
    }

    /**
     * Closes the vigent ocupación of $plazaId and opens a new one for
     * $newPlayerId, in one transaction — a "cambio" or a "cambio de cambio"
     * are the exact same call here; see Plazas\CadenaResolver's class
     * docblock for why this model needs no special case for the latter.
     *
     * @param string $cerradaPor Why the closed link ended — 'reemplazada'
     *        (the outgoing occupant was still within their rights, simply
     *        superseded) or 'trunca' (the outgoing occupant left before
     *        meeting the 3-fecha minimum, and is now blocked — see
     *        CadenaResolver::listExOcupantesBloqueados()). 'regreso_titular' is
     *        NOT accepted here — see closeOcupacionByRegresoTitular().
     *
     * @throws \InvalidArgumentException When $cerradaPor is not 'reemplazada'
     *         or 'trunca'.
     * @throws \RuntimeException When $plazaId has no vigent ocupación to
     *         succeed.
     * @throws PlazaPersistenceException When either write fails at the wpdb
     *         level — thrown BEFORE the COMMIT, so neither the close nor the
     *         new link is left partially applied.
     */
    public function succeedOcupacion( int $plazaId, int $newPlayerId, int $fechaId, string $cerradaPor, string $now ): int {
        if ( ! in_array( $cerradaPor, [ 'reemplazada', 'trunca' ], true ) ) {
            throw new \InvalidArgumentException(
                "PlazaRepository::succeedOcupacion(): '{$cerradaPor}' is not a valid cerrada_por for this method. "
                . "Valid values are: 'reemplazada', 'trunca'."
            );
        }

        $vigente = $this->findOcupacionVigente( $plazaId );

        if ( null === $vigente ) {
            throw new \RuntimeException(
                "PlazaRepository::succeedOcupacion(): plaza {$plazaId} has no vigent ocupación to succeed."
            );
        }

        $wpdb = $this->wpdb;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $this->closeOcupacion( (int) $vigente['id'], $fechaId, $cerradaPor );
            $newId = $this->insertOcupacion( $plazaId, $newPlayerId, false, $fechaId, $now );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        return $newId;
    }

    /**
     * Closes the vigent ocupación of $plazaId with `cerrada_por =
     * 'regreso_titular'` and opens a new one for the plaza's PERMANENT
     * titular (`cambios_plaza.titular_player_id`) — NEVER for a suplente
     * intermedio, no matter who is vigent when this is called. See
     * Plazas\CadenaResolver's class docblock: "el que regresa es SIEMPRE el
     * titular original" is the rule this method exists to enforce
     * mechanically.
     *
     * IDEMPOTENT: if the titular is already the vigent occupant, this is a
     * no-op — there is nothing to return from — and the existing vigent
     * ocupación's id is returned unchanged, mirroring
     * CapitanRepository::designateCapitan()'s idempotency on the incumbent.
     *
     * @throws \RuntimeException When $plazaId does not exist, or has no
     *         vigent ocupación to close.
     * @throws PlazaPersistenceException When either write fails at the wpdb
     *         level — thrown BEFORE the COMMIT.
     */
    public function closeOcupacionByRegresoTitular( int $plazaId, int $fechaId, string $now ): int {
        $plaza = $this->findPlaza( $plazaId );

        if ( null === $plaza ) {
            throw new \RuntimeException(
                "PlazaRepository::closeOcupacionByRegresoTitular(): plaza {$plazaId} does not exist."
            );
        }

        $vigente = $this->findOcupacionVigente( $plazaId );

        if ( null === $vigente ) {
            throw new \RuntimeException(
                "PlazaRepository::closeOcupacionByRegresoTitular(): plaza {$plazaId} has no vigent ocupación to close."
            );
        }

        $titularPlayerId = (int) $plaza['titular_player_id'];

        if ( (int) $vigente['player_id'] === $titularPlayerId ) {
            return (int) $vigente['id'];
        }

        $wpdb = $this->wpdb;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $this->closeOcupacion( (int) $vigente['id'], $fechaId, 'regreso_titular' );
            $newId = $this->insertOcupacion( $plazaId, $titularPlayerId, false, $fechaId, $now );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        return $newId;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Closes exactly ONE vigent ocupación via a compare-and-swap UPDATE — the
     * WHERE clause requires `fecha_hasta_id IS NULL`, which `$wpdb->update()`
     * cannot express (it only builds `col = value` equality pairs), hence the
     * raw `$wpdb->query( $wpdb->prepare( ... ) )` here instead.
     *
     * WHY THIS MATTERS: two concurrent requests can both read the same
     * vigent ocupación (via findOcupacionVigente()) before either one closes
     * it. Without the `IS NULL` guard, a plain `UPDATE ... WHERE id = %d`
     * would let BOTH requests "succeed" — the second one closes an
     * already-closed row, silently affecting 0 rows (wpdb's insert()/update()
     * never throw on failure, they return a row count) — and both then
     * insert their own successor link, leaving the plaza with two vigent
     * ocupaciones. Requiring `fecha_hasta_id IS NULL` in the WHERE, and
     * requiring the result to be EXACTLY 1 affected row, turns that silent
     * lost-update race into a hard failure for whichever caller loses the
     * race — the transaction rolls back (see succeedOcupacion() /
     * closeOcupacionByRegresoTitular()) instead of persisting a corrupted
     * chain.
     *
     * @throws PlazaPersistenceException When the UPDATE affects zero rows
     *         (lost the race, or $ocupacionId was already closed) or the
     *         wpdb-level query itself fails.
     */
    private function closeOcupacion( int $ocupacionId, int $fechaHastaId, string $cerradaPor ): void {
        if ( ! in_array( $cerradaPor, self::VALID_CERRADA_POR, true ) ) {
            throw new \InvalidArgumentException(
                "PlazaRepository: '{$cerradaPor}' is not a valid cerrada_por. "
                . 'Valid values are: ' . implode( ', ', self::VALID_CERRADA_POR ) . '.'
            );
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "UPDATE {$p}cambios_ocupacion
                    SET fecha_hasta_id = %d, cerrada_por = %s
                  WHERE id = %d AND fecha_hasta_id IS NULL",
                $fechaHastaId,
                $cerradaPor,
                $ocupacionId
            )
        );

        if ( 1 !== $affected ) {
            throw new PlazaPersistenceException(
                "close cambios_ocupacion (id={$ocupacionId}, affected="
                    . var_export( $affected, true )
                    . ', expected exactly 1 — likely a concurrent close of the same ocupación)',
                $wpdb->last_error
            );
        }
    }

    /**
     * @param bool $esGenesis Whether this is the plaza's FOUNDING link — see
     *        `cambios_ocupacion.es_genesis`'s docblock in
     *        Migrations\InitialSchema::sqlCambiosOcupacion(). Only
     *        openPlaza() ever passes `true`.
     *
     * @throws PlazaPersistenceException When $wpdb->insert() fails.
     */
    private function insertOcupacion( int $plazaId, int $playerId, bool $esGenesis, int $fechaDesdeId, string $now ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->insert(
            $p . 'cambios_ocupacion',
            [
                'plaza_id'       => $plazaId,
                'player_id'      => $playerId,
                'es_genesis'     => $esGenesis ? 1 : 0,
                'fecha_desde_id' => $fechaDesdeId,
                'fecha_hasta_id' => null,
                'cerrada_por'    => null,
                'created_at'     => $now,
            ]
        );

        if ( false === $result ) {
            throw new PlazaPersistenceException( 'insert cambios_ocupacion', $wpdb->last_error );
        }

        $id = (int) $wpdb->insert_id;

        if ( $id <= 0 ) {
            throw new PlazaPersistenceException( 'insert cambios_ocupacion', $wpdb->last_error );
        }

        return $id;
    }
}
