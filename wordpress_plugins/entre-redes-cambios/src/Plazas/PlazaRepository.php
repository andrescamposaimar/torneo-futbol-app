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
     * `es_titular = 1`, occupying it from `$fechaDesdeId`) in one
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
    public function listPlazasByTeam( int $seasonId, int $teamId ): array {
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
     */
    public function findOcupacionVigente( int $plazaId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_ocupacion
                  WHERE plaza_id = %d AND fecha_hasta_id IS NULL
                  LIMIT 1",
                $plazaId
            ),
            ARRAY_A
        );

        return empty( $row ) ? null : $row;
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
     *        CadenaResolver::exOcupantesBloqueados()). 'regreso_titular' is
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
     * @throws PlazaPersistenceException When $wpdb->update() returns `false`.
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

        $result = $wpdb->update(
            $p . 'cambios_ocupacion',
            [
                'fecha_hasta_id' => $fechaHastaId,
                'cerrada_por'    => $cerradaPor,
            ],
            [ 'id' => $ocupacionId ]
        );

        if ( false === $result ) {
            throw new PlazaPersistenceException( 'close cambios_ocupacion', $wpdb->last_error );
        }
    }

    /**
     * @throws PlazaPersistenceException When $wpdb->insert() fails.
     */
    private function insertOcupacion( int $plazaId, int $playerId, bool $esTitular, int $fechaDesdeId, string $now ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->insert(
            $p . 'cambios_ocupacion',
            [
                'plaza_id'       => $plazaId,
                'player_id'      => $playerId,
                'es_titular'     => $esTitular ? 1 : 0,
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
