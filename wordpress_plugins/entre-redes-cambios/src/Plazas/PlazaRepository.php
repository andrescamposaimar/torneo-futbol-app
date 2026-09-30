<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\Exception\PlazaPersistenceException;
use EntreRedes\Cambios\Support\OpensTransactions;

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
 *
 * *** OBSERVABILITY (slice 4) ***
 * The EventLog is a MANDATORY constructor dependency, with no null-object
 * default — see Observability\EventLog's class docblock for why a silent
 * default would reproduce the exact problem this slice exists to fix. Every
 * successful write that matters to an operator (openPlaza, succeedOcupacion,
 * closeOcupacionByRegresoTitular's actual change, undoLastOcupacion,
 * closePlaza) records an audit event AFTER its COMMIT. Every failure this
 * class can throw is recorded BEFORE the throw — see each method below for
 * the exact event codes and context.
 *
 * *** FECHA ID VALIDATION (slice 4) ***
 * `fecha_desde_id` / `fecha_hasta_id` are LOGICAL foreign keys into
 * Calendario\FechaRepository's `cambios_fecha` table — nothing in this
 * schema enforces them (see InitialSchema's class docblock: the SQLite test
 * shim drops every KEY, and even in real MySQL these columns carry no FK
 * constraint). Before this slice, an id typo — or an id copied from the
 * wrong season — was written silently and only surfaced much later, when
 * Calendario\FechaRepository::countResolvedFechasSince() tried to resolve
 * it against a season that does not contain it. assertFechaExistsInSeason()
 * below is the guard: openPlaza(), succeedOcupacion() and
 * closeOcupacionByRegresoTitular() all call it before writing anything.
 *
 * *** READ FAILURES MUST NEVER READ AS "NO ROWS" (slice 4b, widened in the
 * read-failure audit) ***
 * `listOcupacionesVigentesDeJugador()` and `listPlazasConCierreTruncadoDeJugador()`
 * feed `Dictamen\DictamenContext` (via `Dictamen\DictamenContextAssembler`),
 * and `Dictamen\Reglas\EntranteDisponible` / `Dictamen\Reglas\EntranteNoBloqueado`
 * both read an EMPTY collection from those accessors as "confirmado, sin
 * conflicto" — see those two classes' docblocks. A `$wpdb->get_results()`
 * call that fails at the wpdb level returns `null` (or, depending on the
 * driver, an empty array while leaving `$wpdb->last_error` non-empty) —
 * either way, indistinguishable from a genuine "no rows" result by return
 * value alone. `assertReadSucceeded()` is the guard every `get_results()`
 * read in this class now calls immediately after the query: it throws —
 * logging `lectura.fallida` first — the instant either signal appears, so a
 * broken query becomes a loud failure instead of a silent authorization.
 *
 * `listPlazasByEquipo()` and `listOcupaciones()` originally predated this
 * guard (slice 4b left them as an accepted, narrower gap — see
 * Dictamen\DictamenContextAssembler's class docblock, "WHY listOcupaciones()
 * ITSELF WAS NOT CHANGED", for the reasoning at the time) but are now routed
 * through it too: `listPlazasByEquipo()` feeds the "Mi Plantel" screen
 * (Rest\PlazasController::listar()`), where a captain reading an empty roster
 * is indistinguishable from "you have no plazas" — and `listOcupaciones()`
 * feeds that same screen's per-plaza `ocupante_player_id` AND the dictamen
 * engine's `DictamenContext::ocupaciones()` — see
 * Dictamen\DictamenContextAssembler's class docblock for why an empty chain
 * there is no longer treated as an acceptable gap either.
 *
 * `findPlaza()` is the one read left NOT routed through `assertReadSucceeded()`
 * — see that method's own docblock for why: every current caller only ever
 * treats a `null` return as a reason to refuse/report "plaza not found",
 * never as permission, so the narrower gap is intentional there, unlike the
 * two methods above. `findOcupacionVigente()` already calls
 * `assertReadSucceeded()` (see its own docblock) — it was NOT left unchanged
 * the way this section used to claim; that was a stale claim in this
 * docblock, corrected here.
 *
 * *** "WithinTransaction" VARIANTS (slice 4c, widened for the plaza importer) ***
 * `succeedOcupacionWithinTransaction()` and
 * `closeOcupacionByRegresoTitularWithinTransaction()` exist for exactly one
 * caller: `Solicitudes\SolicitudRepository::publicarLote()`, which must
 * apply an entire Friday lote of solicitudes as ONE atomic database
 * transaction — a nested `START TRANSACTION` per plaza change is not safe
 * across engines (see those methods' own docblocks). `openPlazaWithinTransaction()`
 * exists for the same reason, for `Plazas\PlazaImporter`: a roster backfill
 * opens many plazas across many teams from one CSV, and that whole batch
 * must be all-or-nothing — the same "half-applied is worse than none"
 * reasoning as the Friday lote. All three share every validation guard with
 * their normal counterparts via `prepareSucceedOcupacion()` /
 * `prepareRegresoTitular()` / `validateOpenPlaza()`, but perform the write
 * directly against whatever transaction the caller already opened, and do
 * NOT record their own EventLog event — the caller does, once its own
 * COMMIT has actually succeeded.
 */
class PlazaRepository {

    use OpensTransactions;

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
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
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
     *         self::VALID_TIPOS, or $fechaDesdeId does not exist in
     *         cambios_fecha or belongs to a different season.
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
        $this->beginTransaction( __FUNCTION__ );

        try {
            $plazaId = $this->doOpenPlaza( $seasonId, $teamId, $titularPlayerId, $puntajeTecho, $tipo, $fechaDesdeId, $now );
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e );
            throw $e;
        }

        $this->commitTransaction( __FUNCTION__ );

        $this->eventLog->record( 'plaza.abierta', [
            'plaza_id'           => $plazaId,
            'season_id'          => $seasonId,
            'team_id'            => $teamId,
            'titular_player_id'  => $titularPlayerId,
            'tipo'               => $tipo,
            'fecha_desde_id'     => $fechaDesdeId,
        ] );

        return $plazaId;
    }

    /**
     * Same operation as openPlaza(), but for a caller already running its own
     * transaction spanning more than this one call — `Plazas\PlazaImporter`,
     * which must open every plaza of a CSV backfill as ONE atomic batch. See
     * class docblock, "'WithinTransaction' VARIANTS", and
     * succeedOcupacionWithinTransaction()'s docblock for the full reasoning
     * (nested `START TRANSACTION` is not safe across engines, and success is
     * not known until the CALLER's own COMMIT) — both apply here identically.
     *
     * Does NOT record `plaza.abierta` — the caller does, once its own
     * transaction has actually committed. Every validation guard (invalid
     * $tipo, unknown/foreign $fechaDesdeId) still runs and still logs
     * `escritura.fallida` exactly like openPlaza().
     *
     * @throws \InvalidArgumentException|PlazaPersistenceException Same as openPlaza().
     */
    public function openPlazaWithinTransaction(
        int $seasonId,
        int $teamId,
        int $titularPlayerId,
        Puntaje $puntajeTecho,
        string $tipo,
        int $fechaDesdeId,
        string $now
    ): int {
        return $this->doOpenPlaza( $seasonId, $teamId, $titularPlayerId, $puntajeTecho, $tipo, $fechaDesdeId, $now );
    }

    /**
     * *** WHY A FAILED READ HERE STILL READS AS "NOT FOUND" *** Every current
     * caller (`prepareSucceedOcupacion()`, `prepareRegresoTitular()`,
     * `closePlaza()`, `Dictamen\DictamenContextAssembler::assemble()`,
     * `Rest\PlazasController::listarCandidatos()`) treats a `null` return as
     * a reason to REFUSE the call ("plaza {id} does not exist" /
     * `plaza_no_encontrada`) — never as permission to proceed. Misreading a
     * wpdb-level failure as "not found" here can only ever produce a
     * wrongful denial, the same reasoning as
     * `Capitania\CapitanRepository::findCapitanVigente()` — so this read is
     * deliberately NOT routed through `assertReadSucceeded()`.
     *
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
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** This is
     * the sole data source for `Rest\PlazasController::listar()` — the
     * captain-facing "Mi Plantel" screen. A failed read misread as "this team
     * has no plazas" would render as an empty roster: a captain with real
     * plazas silently told they have none, with no error and no `EventLog`
     * entry anywhere to explain why. See class docblock, "READ FAILURES...".
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level.
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

        $this->assertReadSucceeded( $rows, 'listPlazasByEquipo', [ 'season_id' => $seasonId, 'team_id' => $teamId ] );

        return $rows;
    }

    /**
     * The full chain of ocupaciones for a plaza, ordered chronologically by
     * `fecha_desde_id` (tie-broken by `id` for determinism when two links
     * share the same starting fecha, which should not happen but costs
     * nothing to order deterministically anyway).
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** Two
     * consumers both read an empty chain as a FACT, not as "unknown":
     *   - `Rest\PlazasController::listar()` (the "Mi Plantel" screen) derives
     *     `ocupante_player_id` from this chain's vigent link — an empty
     *     chain renders as "this plaza has no current occupant", which is
     *     never actually true (see `PlazaRepository::openPlaza()`'s genesis
     *     ocupación guarantee) but would look like a legitimate answer to a
     *     captain.
     *   - `Dictamen\DictamenContextAssembler::assemble()` feeds this straight
     *     into `DictamenContext::ocupaciones()`; `DictamenContext::vigente()`
     *     returns null for an empty chain, and
     *     `Dictamen\Reglas\PlazaConOcupacionVigente` reports that as its own
     *     REJECTING motivo (`plaza_sin_ocupacion_vigente`) — so a silent read
     *     failure here already failed CLOSED before this fix, but for the
     *     WRONG reason: "the plaza has no vigent occupant" instead of "the
     *     database could not be read". Verified against
     *     `Dictamen\DictamenPipeline::evaluate()`, which catches \Throwable
     *     from `assemble()`, logs `dictamen.fallido`, and RE-THROWS — it does
     *     NOT swallow this into a fake Dictamen — and against
     *     `Rest\SolicitudesController::crear()`, whose own `\Throwable` catch
     *     logs `rest.solicitud_crear_fallida` and answers a real 500, never a
     *     200 with a rejection motivo. Throwing here therefore keeps both
     *     paths fail-closed and makes the REASON honest.
     *
     * This was slice 4b's one deliberately accepted gap (see
     * `Dictamen\DictamenContextAssembler`'s class docblock, "WHY
     * listOcupaciones() ITSELF WAS NOT CHANGED", now corrected there too) —
     * closed here because a later screen (`Rest\PlazasController`) started
     * reading this same empty-on-failure result as a user-visible fact, which
     * the "fails closed by construction of the ruleset" argument never
     * covered for that consumer.
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level.
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

        $this->assertReadSucceeded( $rows, 'listOcupaciones', [ 'plaza_id' => $plazaId ] );

        return $rows;
    }

    /**
     * Every VIGENT ocupación (`fecha_hasta_id IS NULL`) $playerId currently
     * holds anywhere in $seasonId, across every plaza — optionally excluding
     * one plaza (typically the plaza a solicitud is being evaluated
     * against, so a player is never reported as "already occupying" the
     * very plaza the solicitud is for).
     *
     * Feeds `Dictamen\DictamenContext::entranteOcupacionesEnOtrasPlazas()`
     * via `Dictamen\DictamenContextAssembler`. That accessor's own docblock
     * describes receiving BOTH vigent and closed rows so
     * `Dictamen\Reglas\EntranteDisponible` can filter for "vigent" itself —
     * this method pre-filters to vigent rows only, which is sufficient for
     * that rule's actual check ("does at least one vigent row exist
     * elsewhere?") and cheaper than fetching closed rows nobody reads.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** See
     * class docblock, "READ FAILURES MUST NEVER READ AS 'NO ROWS'".
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    public function listOcupacionesVigentesDeJugador( int $seasonId, int $playerId, ?int $excluyendoPlazaId = null ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $sql = "SELECT o.* FROM {$p}cambios_ocupacion o
                  INNER JOIN {$p}cambios_plaza pl ON pl.id = o.plaza_id
                 WHERE pl.season_id = %d
                   AND o.player_id = %d
                   AND o.fecha_hasta_id IS NULL";
        $args = [ $seasonId, $playerId ];

        if ( null !== $excluyendoPlazaId ) {
            $sql   .= ' AND o.plaza_id != %d';
            $args[] = $excluyendoPlazaId;
        }

        $sql .= ' ORDER BY o.plaza_id ASC, o.id ASC';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        $this->assertReadSucceeded( $rows, 'listOcupacionesVigentesDeJugador', [
            'season_id'           => $seasonId,
            'player_id'           => $playerId,
            'excluyendo_plaza_id' => $excluyendoPlazaId,
        ] );

        return $rows;
    }

    /**
     * Every OTHER plaza's FULL ocupaciones chain where $playerId left a
     * link closed `cerrada_por = 'trunca'`, within $seasonId — one chain per
     * distinct plaza, in this class's own `listOcupaciones()` shape, so
     * `Plazas\CadenaResolver` can be handed each one directly to decide
     * whether that plaza has liberated since (unblocking every trunca
     * ex-occupant at once — see CadenaResolver's class docblock).
     *
     * Feeds `Dictamen\DictamenContext::entrantePlazasConCierreTruncado()`
     * via `Dictamen\DictamenContextAssembler`; `Dictamen\Reglas\EntranteNoBloqueado`
     * is the consumer.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** See
     * class docblock. Uses `listOcupacionesOrThrow()`, NOT the public
     * `listOcupaciones()`, for the per-plaza chain fetch below — that public
     * method silently returns `[]` on a query failure (see class docblock,
     * "READ FAILURES..."), which would let a broken chain fetch collapse
     * into "this plaza has no trunca closures", exactly the silent
     * authorization this method must never produce.
     *
     * @return array<int, array<int, array<string, mixed>>>
     * @throws \RuntimeException When either the plaza-id lookup or any
     *         individual chain fetch fails at the wpdb level.
     */
    public function listPlazasConCierreTruncadoDeJugador( int $seasonId, int $playerId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $plazaIdRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT o.plaza_id AS plaza_id
                   FROM {$p}cambios_ocupacion o
                   INNER JOIN {$p}cambios_plaza pl ON pl.id = o.plaza_id
                  WHERE pl.season_id = %d
                    AND o.player_id = %d
                    AND o.cerrada_por = 'trunca'
                  ORDER BY o.plaza_id ASC",
                $seasonId,
                $playerId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $plazaIdRows, 'listPlazasConCierreTruncadoDeJugador', [
            'season_id' => $seasonId,
            'player_id' => $playerId,
        ] );

        $chains = [];

        foreach ( $plazaIdRows as $row ) {
            $chains[] = $this->listOcupacionesOrThrow( (int) $row['plaza_id'], 'listPlazasConCierreTruncadoDeJugador' );
        }

        return $chains;
    }

    /**
     * @return array<string, mixed>|null The vigent (`fecha_hasta_id IS
     *         NULL`) ocupación of this plaza, or null when the plaza somehow
     *         has none open — should not happen once openPlaza() has run,
     *         but callers should not assume it.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN NULL ON A QUERY FAILURE ***
     * `Plazas\CandidatosResolver::paraPlaza()` calls this to find the plaza's
     * incumbent so it can be excluded from the candidate pool — a failed read
     * here misread as "no vigent occupant" would silently put the current
     * occupant back into their OWN candidate pool. See class docblock,
     * "READ FAILURES MUST NEVER READ AS 'NO ROWS'".
     *
     * @throws \RuntimeException When the query fails at the wpdb level (see
     *         above), OR when MORE THAN ONE ocupación of this plaza is
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

        $this->assertReadSucceeded( $rows, 'findOcupacionVigente', [ 'plaza_id' => $plazaId ] );

        if ( empty( $rows ) ) {
            return null;
        }

        if ( count( $rows ) > 1 ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'          => 'findOcupacionVigente',
                'motivo'             => 'mas de una ocupacion vigente para la misma plaza',
                'plaza_id'           => $plazaId,
                'ocupaciones_vigentes' => count( $rows ),
            ] );

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
     *         or 'trunca', or $fechaId does not exist in cambios_fecha or
     *         belongs to a different season than the plaza.
     * @throws \RuntimeException When $plazaId does not exist, or has no
     *         vigent ocupación to succeed.
     * @throws PlazaPersistenceException When either write fails at the wpdb
     *         level — thrown BEFORE the COMMIT, so neither the close nor the
     *         new link is left partially applied.
     */
    public function succeedOcupacion( int $plazaId, int $newPlayerId, int $fechaId, string $cerradaPor, string $now ): int {
        [ $plaza, $vigente ] = $this->prepareSucceedOcupacion( $plazaId, $newPlayerId, $fechaId, $cerradaPor );

        $wpdb = $this->wpdb;

        $this->beginTransaction( __FUNCTION__ );

        try {
            $this->closeOcupacion( (int) $vigente['id'], $fechaId, $cerradaPor );
            $newId = $this->insertOcupacion( $plazaId, $newPlayerId, false, $fechaId, $now );
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e );
            throw $e;
        }

        $this->commitTransaction( __FUNCTION__ );

        $this->eventLog->record( 'ocupacion.sucedida', [
            'plaza_id'             => $plazaId,
            'season_id'            => (int) $plaza['season_id'],
            'team_id'              => (int) $plaza['team_id'],
            'ocupacion_cerrada_id' => (int) $vigente['id'],
            'saliente_player_id'   => (int) $vigente['player_id'],
            'entrante_player_id'   => $newPlayerId,
            'ocupacion_nueva_id'   => $newId,
            'fecha_id'             => $fechaId,
            'cerrada_por'          => $cerradaPor,
        ] );

        return $newId;
    }

    /**
     * Same operation as succeedOcupacion(), but for a caller that is already
     * running its own database transaction spanning MORE than this one call
     * — `Solicitudes\SolicitudRepository::publicarLote()`, which must apply
     * an entire lote of solicitudes atomically as ONE transaction (see that
     * class's docblock, "WHY THIS METHOD EXISTS", for the full reasoning).
     *
     * THIS METHOD DOES NEITHER OF THE TWO THINGS succeedOcupacion() DOES
     * AROUND THE ACTUAL WRITE:
     *
     * 1. It does NOT open or close a transaction of its own. Issuing a
     *    nested `START TRANSACTION` while the caller's own transaction is
     *    already open is not safe to rely on across engines — MySQL
     *    implicitly commits the OUTER transaction the instant a nested one
     *    starts, and the SQLite test shim's driver refuses a nested `BEGIN`
     *    outright (see tests/wp-shim.php) — either behavior would silently
     *    break the caller's atomicity guarantee. The write below therefore
     *    runs directly against whatever transaction the caller already
     *    started, and a thrown exception here is meant to propagate straight
     *    into the caller's own `catch` / `ROLLBACK`.
     * 2. It does NOT record `ocupacion.sucedida`. Whether this write
     *    actually survives is only known once the CALLER's own COMMIT
     *    succeeds — logging success here, before that COMMIT, could record
     *    an event for a write the caller goes on to roll back. The caller
     *    is responsible for its own EventLog record once its transaction
     *    has actually committed.
     *
     * Every validation guard (invalid $cerradaPor, missing plaza, missing
     * fecha, missing vigent ocupación) still runs and still logs
     * `escritura.fallida` exactly like succeedOcupacion() — a caller-side
     * transaction changes nothing about what makes this call invalid.
     *
     * @throws \InvalidArgumentException|\RuntimeException|PlazaPersistenceException Same as succeedOcupacion().
     */
    public function succeedOcupacionWithinTransaction( int $plazaId, int $newPlayerId, int $fechaId, string $cerradaPor, string $now ): int {
        [ , $vigente ] = $this->prepareSucceedOcupacion( $plazaId, $newPlayerId, $fechaId, $cerradaPor );

        $this->closeOcupacion( (int) $vigente['id'], $fechaId, $cerradaPor );

        return $this->insertOcupacion( $plazaId, $newPlayerId, false, $fechaId, $now );
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
     * No audit event is recorded for this no-op branch: nothing was written.
     *
     * @throws \RuntimeException When $plazaId does not exist, or has no
     *         vigent ocupación to close.
     * @throws \InvalidArgumentException When $fechaId does not exist in
     *         cambios_fecha or belongs to a different season than the plaza.
     * @throws PlazaPersistenceException When either write fails at the wpdb
     *         level — thrown BEFORE the COMMIT.
     */
    public function closeOcupacionByRegresoTitular( int $plazaId, int $fechaId, string $now ): int {
        $prep = $this->prepareRegresoTitular( $plazaId, $fechaId );

        if ( $prep['noop'] ) {
            return $prep['vigenteId'];
        }

        [ 'plaza' => $plaza, 'vigente' => $vigente, 'titularPlayerId' => $titularPlayerId ] = $prep;

        $wpdb = $this->wpdb;

        $this->beginTransaction( __FUNCTION__ );

        try {
            $this->closeOcupacion( (int) $vigente['id'], $fechaId, 'regreso_titular' );
            $newId = $this->insertOcupacion( $plazaId, $titularPlayerId, false, $fechaId, $now );
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e );
            throw $e;
        }

        $this->commitTransaction( __FUNCTION__ );

        $this->eventLog->record( 'ocupacion.regreso_titular', [
            'plaza_id'             => $plazaId,
            'season_id'            => (int) $plaza['season_id'],
            'team_id'              => (int) $plaza['team_id'],
            'ocupacion_cerrada_id' => (int) $vigente['id'],
            'suplente_player_id'   => (int) $vigente['player_id'],
            'titular_player_id'    => $titularPlayerId,
            'ocupacion_nueva_id'   => $newId,
            'fecha_id'             => $fechaId,
        ] );

        return $newId;
    }

    /**
     * Same operation as closeOcupacionByRegresoTitular(), but for a caller
     * already running its own transaction spanning more than this one call
     * — see succeedOcupacionWithinTransaction()'s docblock for the full
     * reasoning (nested `START TRANSACTION` is not safe to rely on across
     * engines, and success is not known until the CALLER's own COMMIT), which
     * applies here identically. Same idempotency as
     * closeOcupacionByRegresoTitular(): a no-op, still WITHOUT recording an
     * event, when the titular is already the vigent occupant.
     *
     * @throws \RuntimeException|\InvalidArgumentException|PlazaPersistenceException Same as closeOcupacionByRegresoTitular().
     */
    public function closeOcupacionByRegresoTitularWithinTransaction( int $plazaId, int $fechaId, string $now ): int {
        $prep = $this->prepareRegresoTitular( $plazaId, $fechaId );

        if ( $prep['noop'] ) {
            return $prep['vigenteId'];
        }

        $vigente         = $prep['vigente'];
        $titularPlayerId = $prep['titularPlayerId'];

        $this->closeOcupacion( (int) $vigente['id'], $fechaId, 'regreso_titular' );

        return $this->insertOcupacion( $plazaId, $titularPlayerId, false, $fechaId, $now );
    }

    /**
     * *** CORRECTION PRIMITIVE — NOT PART OF THE NORMAL FLOW ***
     * Undoes the LAST link of a plaza's chain: deletes it and reopens the
     * link before it (`fecha_hasta_id = NULL`, `cerrada_por = NULL`), inside
     * one transaction.
     *
     * This exists because, before this slice, the only way to fix "a
     * plaza opened with the wrong titular" or "an ocupación succeeded by
     * mistake" was raw SQL against a production database — and the person
     * operating this plugin day to day is a parents' committee, not a
     * developer. This is a correction tool for that committee's operator to
     * use through a future admin action, not something the domain logic
     * (Dictamen\DictamenEngine, CadenaResolver) ever calls as part of a
     * normal solicitud/regreso — a normal chain only ever grows via
     * succeedOcupacion() / closeOcupacionByRegresoTitular(), never shrinks.
     *
     * *** THIS METHOD DOES NOT AUTHORIZE THE CALLER *** One of the most
     * dangerous entry points in this plugin to expose — it deletes a real
     * audit row and rewrites a plaza's current occupant. It performs NO role
     * or ownership check of its own. A future REST wrapper MUST verify the
     * caller's role and their standing to act on THIS plaza's team BEFORE
     * invoking this — never rely on this method to reject an unauthorized
     * caller, because it will not.
     *
     * Refuses to touch the plaza's GENESIS link — a chain with exactly one
     * ocupación has nothing to "undo back to"; closePlaza() is the correct
     * tool for a plaza that was opened by mistake entirely.
     *
     * @throws \RuntimeException When the plaza has only its genesis
     *         ocupación (nothing to undo), or when its chain is corrupted
     *         (the last link is not the vigent one — should never happen
     *         through this class's own API, but this method refuses to
     *         guess which link to undo rather than silently picking one).
     * @throws PlazaPersistenceException When either write fails at the wpdb
     *         level — thrown BEFORE the COMMIT, so neither the delete nor
     *         the reopen is left partially applied.
     */
    public function undoLastOcupacion( int $plazaId, string $now ): void {
        $chain = $this->listOcupaciones( $plazaId );

        if ( count( $chain ) < 2 ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => 'undoLastOcupacion',
                'motivo'    => 'la plaza solo tiene su ocupacion genesis, nada para deshacer',
                'plaza_id'  => $plazaId,
                'eslabones' => count( $chain ),
            ] );

            throw new \RuntimeException(
                "PlazaRepository::undoLastOcupacion(): plaza {$plazaId} has only its genesis ocupación — "
                . 'there is nothing to undo. Use closePlaza() to close a plaza opened by mistake.'
            );
        }

        $last     = $chain[ count( $chain ) - 1 ];
        $previous = $chain[ count( $chain ) - 2 ];

        if ( null !== $last['fecha_hasta_id'] ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'    => 'undoLastOcupacion',
                'motivo'       => 'el ultimo eslabon de la cadena no esta vigente, cadena corrupta',
                'plaza_id'     => $plazaId,
                'ocupacion_id' => (int) $last['id'],
            ] );

            throw new \RuntimeException(
                "PlazaRepository::undoLastOcupacion(): plaza {$plazaId}'s last ocupación (id="
                . (int) $last['id'] . ') is not vigent — the chain is corrupted; refusing to guess which link to undo.'
            );
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $this->beginTransaction( __FUNCTION__ );

        try {
            $deleted = $wpdb->delete( $p . 'cambios_ocupacion', [ 'id' => (int) $last['id'] ] );

            if ( 1 !== $deleted ) {
                $this->eventLog->record( 'escritura.fallida', [
                    'operacion'    => 'undoLastOcupacion',
                    'motivo'       => 'delete cambios_ocupacion fallo',
                    'plaza_id'     => $plazaId,
                    'ocupacion_id' => (int) $last['id'],
                    'last_error'   => $wpdb->last_error,
                ] );

                throw new PlazaPersistenceException( 'delete cambios_ocupacion (undo)', $wpdb->last_error );
            }

            $reopened = $wpdb->update(
                $p . 'cambios_ocupacion',
                [
                    'fecha_hasta_id' => null,
                    'cerrada_por'    => null,
                ],
                [ 'id' => (int) $previous['id'] ]
            );

            if ( 1 !== $reopened ) {
                $this->eventLog->record( 'escritura.fallida', [
                    'operacion'    => 'undoLastOcupacion',
                    'motivo'       => 'reopen cambios_ocupacion fallo',
                    'plaza_id'     => $plazaId,
                    'ocupacion_id' => (int) $previous['id'],
                    'last_error'   => $wpdb->last_error,
                ] );

                throw new PlazaPersistenceException( 'reopen cambios_ocupacion (undo)', $wpdb->last_error );
            }
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e );
            throw $e;
        }

        $this->commitTransaction( __FUNCTION__ );

        $this->eventLog->record( 'ocupacion.deshecha', [
            'plaza_id'               => $plazaId,
            'ocupacion_deshecha_id'  => (int) $last['id'],
            'ocupacion_reabierta_id' => (int) $previous['id'],
            'now'                    => $now,
        ] );
    }

    /**
     * *** CORRECTION PRIMITIVE — NOT PART OF THE NORMAL FLOW ***
     * Marks a plaza as closed (`closed_at`) — for a plaza opened entirely by
     * mistake (wrong team, wrong titular, duplicate conformación). This is
     * NOT how an ocupación record ends (that is `cambios_ocupacion`'s
     * `fecha_hasta_id` — see InitialSchema's docblock); it is how the PLAZA
     * itself stops existing. Same rationale as undoLastOcupacion(): the
     * alternative, absent this method, is raw SQL run by a parents'
     * committee against production.
     *
     * *** THIS METHOD DOES NOT AUTHORIZE THE CALLER *** Same warning as
     * undoLastOcupacion(): it performs NO role or ownership check of its
     * own. A future REST wrapper MUST verify the caller's role and their
     * standing to act on THIS plaza's team BEFORE invoking this — never rely
     * on this method to reject an unauthorized caller, because it will not.
     *
     * @throws \RuntimeException When $plazaId does not exist.
     * @throws PlazaPersistenceException When the wpdb update fails.
     */
    public function closePlaza( int $plazaId, string $now ): void {
        $plaza = $this->findPlaza( $plazaId );

        if ( null === $plaza ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => 'closePlaza',
                'motivo'    => 'la plaza no existe',
                'plaza_id'  => $plazaId,
            ] );

            throw new \RuntimeException(
                "PlazaRepository::closePlaza(): plaza {$plazaId} does not exist."
            );
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->update(
            $p . 'cambios_plaza',
            [ 'closed_at' => $now ],
            [ 'id' => $plazaId ]
        );

        if ( false === $result ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'closePlaza',
                'motivo'     => 'update cambios_plaza fallo',
                'plaza_id'   => $plazaId,
                'season_id'  => (int) $plaza['season_id'],
                'team_id'    => (int) $plaza['team_id'],
                'last_error' => $wpdb->last_error,
            ] );

            throw new PlazaPersistenceException( 'close cambios_plaza', $wpdb->last_error );
        }

        $this->eventLog->record( 'plaza.cerrada', [
            'plaza_id'  => $plazaId,
            'season_id' => (int) $plaza['season_id'],
            'team_id'   => (int) $plaza['team_id'],
            'now'       => $now,
        ] );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * The validation AND the write openPlaza() / openPlazaWithinTransaction()
     * both need — shared here, exactly like prepareSucceedOcupacion() /
     * prepareRegresoTitular() below, so the two public entry points can never
     * drift on what counts as a valid call or how the two rows get written.
     * The operation name logged on every guard below is always the literal
     * `'openPlaza'`, regardless of which public method called this — mirrors
     * prepareSucceedOcupacion()'s own choice to log `'succeedOcupacion'`
     * unconditionally.
     *
     * @throws \InvalidArgumentException When $tipo is not one of
     *         self::VALID_TIPOS, or $fechaDesdeId does not exist in
     *         cambios_fecha or belongs to a different season.
     * @throws PlazaPersistenceException When either insert fails at the wpdb
     *         level.
     */
    private function doOpenPlaza(
        int $seasonId,
        int $teamId,
        int $titularPlayerId,
        Puntaje $puntajeTecho,
        string $tipo,
        int $fechaDesdeId,
        string $now
    ): int {
        if ( ! in_array( $tipo, self::VALID_TIPOS, true ) ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'openPlaza',
                'motivo'     => 'tipo invalido',
                'season_id'  => $seasonId,
                'team_id'    => $teamId,
                'tipo'       => $tipo,
            ] );

            throw new \InvalidArgumentException(
                "PlazaRepository::openPlaza(): '{$tipo}' is not a valid tipo. "
                . 'Valid values are: ' . implode( ', ', self::VALID_TIPOS ) . '.'
            );
        }

        $this->assertFechaExistsInSeason( $fechaDesdeId, $seasonId, 'openPlaza', 'fecha_desde_id' );

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

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
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'openPlaza',
                'motivo'     => 'insert cambios_plaza fallo',
                'season_id'  => $seasonId,
                'team_id'    => $teamId,
                'last_error' => $wpdb->last_error,
            ] );

            throw new PlazaPersistenceException( 'insert cambios_plaza', $wpdb->last_error );
        }

        $plazaId = (int) $wpdb->insert_id;

        if ( $plazaId <= 0 ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'openPlaza',
                'motivo'     => 'insert cambios_plaza devolvio insert_id <= 0',
                'season_id'  => $seasonId,
                'team_id'    => $teamId,
                'last_error' => $wpdb->last_error,
            ] );

            throw new PlazaPersistenceException( 'insert cambios_plaza', $wpdb->last_error );
        }

        $this->insertOcupacion(
            $plazaId,
            $titularPlayerId,
            true,
            $fechaDesdeId,
            $now
        );

        return $plazaId;
    }

    /**
     * Every validation guard succeedOcupacion() / succeedOcupacionWithinTransaction()
     * both need BEFORE touching a single row — shared here so the two public
     * methods can never drift on what counts as a valid call.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [$plaza, $vigente]
     * @throws \InvalidArgumentException|\RuntimeException Same as succeedOcupacion().
     */
    private function prepareSucceedOcupacion( int $plazaId, int $newPlayerId, int $fechaId, string $cerradaPor ): array {
        if ( ! in_array( $cerradaPor, [ 'reemplazada', 'trunca' ], true ) ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'   => 'succeedOcupacion',
                'motivo'      => 'cerrada_por invalido',
                'plaza_id'    => $plazaId,
                'cerrada_por' => $cerradaPor,
            ] );

            throw new \InvalidArgumentException(
                "PlazaRepository::succeedOcupacion(): '{$cerradaPor}' is not a valid cerrada_por for this method. "
                . "Valid values are: 'reemplazada', 'trunca'."
            );
        }

        $plaza = $this->findPlaza( $plazaId );

        if ( null === $plaza ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => 'succeedOcupacion',
                'motivo'    => 'la plaza no existe',
                'plaza_id'  => $plazaId,
            ] );

            throw new \RuntimeException(
                "PlazaRepository::succeedOcupacion(): plaza {$plazaId} does not exist."
            );
        }

        $this->assertPlazaNotClosed( $plaza, 'succeedOcupacion' );

        $this->assertFechaExistsInSeason( $fechaId, (int) $plaza['season_id'], 'succeedOcupacion', 'fecha_id', $plazaId );

        $vigente = $this->findOcupacionVigente( $plazaId );

        if ( null === $vigente ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => 'succeedOcupacion',
                'motivo'    => 'la plaza no tiene ocupacion vigente',
                'plaza_id'  => $plazaId,
                'season_id' => (int) $plaza['season_id'],
                'team_id'   => (int) $plaza['team_id'],
            ] );

            throw new \RuntimeException(
                "PlazaRepository::succeedOcupacion(): plaza {$plazaId} has no vigent ocupación to succeed."
            );
        }

        return [ $plaza, $vigente ];
    }

    /**
     * Every validation guard closeOcupacionByRegresoTitular() /
     * closeOcupacionByRegresoTitularWithinTransaction() both need, PLUS the
     * idempotency check ("is the titular already vigent?") both must apply
     * identically — see those two methods' docblocks.
     *
     * @return array{noop: bool, vigenteId?: int, plaza?: array<string, mixed>, vigente?: array<string, mixed>, titularPlayerId?: int}
     *         `noop: true` (with `vigenteId` set) when the titular is already
     *         the vigent occupant — nothing to close, nothing to insert.
     *         Otherwise `noop: false` plus every value the two callers need
     *         to perform the actual write.
     * @throws \InvalidArgumentException|\RuntimeException Same as closeOcupacionByRegresoTitular().
     */
    private function prepareRegresoTitular( int $plazaId, int $fechaId ): array {
        $plaza = $this->findPlaza( $plazaId );

        if ( null === $plaza ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => 'closeOcupacionByRegresoTitular',
                'motivo'    => 'la plaza no existe',
                'plaza_id'  => $plazaId,
            ] );

            throw new \RuntimeException(
                "PlazaRepository::closeOcupacionByRegresoTitular(): plaza {$plazaId} does not exist."
            );
        }

        $this->assertPlazaNotClosed( $plaza, 'closeOcupacionByRegresoTitular' );

        $this->assertFechaExistsInSeason( $fechaId, (int) $plaza['season_id'], 'closeOcupacionByRegresoTitular', 'fecha_id', $plazaId );

        $vigente = $this->findOcupacionVigente( $plazaId );

        if ( null === $vigente ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => 'closeOcupacionByRegresoTitular',
                'motivo'    => 'la plaza no tiene ocupacion vigente',
                'plaza_id'  => $plazaId,
                'season_id' => (int) $plaza['season_id'],
                'team_id'   => (int) $plaza['team_id'],
            ] );

            throw new \RuntimeException(
                "PlazaRepository::closeOcupacionByRegresoTitular(): plaza {$plazaId} has no vigent ocupación to close."
            );
        }

        $titularPlayerId = (int) $plaza['titular_player_id'];

        if ( (int) $vigente['player_id'] === $titularPlayerId ) {
            return [ 'noop' => true, 'vigenteId' => (int) $vigente['id'] ];
        }

        return [
            'noop'            => false,
            'plaza'           => $plaza,
            'vigente'         => $vigente,
            'titularPlayerId' => $titularPlayerId,
        ];
    }

    /**
     * Same query as listOcupaciones(), but FAILS LOUD on a wpdb-level query
     * failure instead of silently returning `[]` — see
     * listPlazasConCierreTruncadoDeJugador()'s docblock for why that method
     * cannot use the public listOcupaciones() for its per-plaza chain fetch.
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function listOcupacionesOrThrow( int $plazaId, string $operacion ): array {
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

        $this->assertReadSucceeded( $rows, $operacion, [ 'plaza_id' => $plazaId ] );

        return $rows;
    }

    /**
     * The one place `listOcupacionesVigentesDeJugador()`,
     * `listPlazasConCierreTruncadoDeJugador()` and `listOcupacionesOrThrow()`
     * check whether their own `get_results()` call actually succeeded — see
     * class docblock, "READ FAILURES MUST NEVER READ AS 'NO ROWS'".
     *
     * A genuine "no matching rows" result is `$rows === []` with
     * `$wpdb->last_error` empty — that passes through untouched. Anything
     * else (`$rows === null`, which is wpdb's own documented failure return,
     * OR a non-empty `$wpdb->last_error` left over from THIS call) is a
     * query failure: logged as `lectura.fallida` (mirroring every
     * `escritura.fallida` write-failure event elsewhere in this class), then
     * thrown, so the caller can never mistake it for "confirmado, sin
     * conflicto".
     *
     * @param array<int, array<string, mixed>>|null $rows
     * @param array<string, mixed>                  $contexto
     * @throws \RuntimeException
     */
    private function assertReadSucceeded( ?array $rows, string $operacion, array $contexto ): void {
        $lastError = (string) ( $this->wpdb->last_error ?? '' );

        if ( null !== $rows && '' === $lastError ) {
            return;
        }

        $this->eventLog->record( 'lectura.fallida', array_merge( $contexto, [
            'operacion'  => $operacion,
            'last_error' => $this->wpdb->last_error,
        ] ) );

        throw new \RuntimeException(
            sprintf(
                "PlazaRepository::%s(): the query failed at the wpdb level%s.",
                $operacion,
                '' !== $lastError ? " ({$lastError})" : ''
            )
        );
    }

    /**
     * DEFENSE IN DEPTH for closePlaza() — see that method's class docblock
     * and Dictamen\Reglas\PlazaNoCerrada's own docblock for the full story:
     * a `closePlaza()` correction is meant to make a plaza's data inert.
     * Before this guard, nothing in this repository itself enforced that —
     * only the dictamen ruleset did, and a solicitud created BEFORE the
     * closure (or one whose fresh re-evaluation somehow still cleared every
     * rule) could still reach succeedOcupacion() /
     * closeOcupacionByRegresoTitular() and write over a plaza the operator
     * had explicitly marked as not existing. Called by both
     * prepareSucceedOcupacion() and prepareRegresoTitular(), so all FOUR
     * public entry points that end up calling either one — including the
     * two "WithinTransaction" variants `Solicitudes\SolicitudRepository::
     * publicarLote()` uses — refuse identically, never only the ones that
     * happen to open their own transaction.
     *
     * @param array<string, mixed> $plaza As returned by findPlaza().
     * @throws \RuntimeException When the plaza has a non-null `closed_at`.
     */
    private function assertPlazaNotClosed( array $plaza, string $operacion ): void {
        if ( null === $plaza['closed_at'] ) {
            return;
        }

        $plazaId = (int) $plaza['id'];

        $this->eventLog->record( 'escritura.fallida', [
            'operacion' => $operacion,
            'motivo'    => 'la plaza esta cerrada (closed_at no nulo)',
            'plaza_id'  => $plazaId,
            'season_id' => (int) $plaza['season_id'],
            'team_id'   => (int) $plaza['team_id'],
            'closed_at' => $plaza['closed_at'],
        ] );

        throw new \RuntimeException(
            "PlazaRepository::{$operacion}(): plaza {$plazaId} is closed (closed_at="
            . (string) $plaza['closed_at'] . ') — refusing to write over a plaza the operator marked as not existing.'
        );
    }

    /**
     * Guards every `fecha_desde_id` / `fecha_hasta_id` this class persists —
     * see class docblock, "FECHA ID VALIDATION". Read-only; called before
     * any write starts, so a rejected id never begins a transaction that
     * would only be rolled back.
     *
     * @throws \InvalidArgumentException When $fechaId does not exist in
     *         cambios_fecha, or exists but belongs to a different season — OR
     *         when the lookup itself fails at the wpdb level. `get_row()`
     *         returns `null` for both, and this guard already rejects `null`
     *         unconditionally: a wpdb-level failure here can only ever
     *         produce this SAME wrongful denial (a write refused for a
     *         fecha_id that actually exists), never a wrongful grant, so it
     *         is intentionally NOT split into a separate `\RuntimeException`
     *         path the way `Calendario\FechaRepository::countResolvedFechasSince()`'s
     *         equivalent lookup now is — the failure mode is identical
     *         either way, only the exception TYPE and message would differ.
     */
    private function assertFechaExistsInSeason( int $fechaId, int $seasonId, string $operacion, string $campo, ?int $plazaId = null ): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT season_id FROM {$p}cambios_fecha WHERE id = %d LIMIT 1",
                $fechaId
            ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => $operacion,
                'motivo'    => "{$campo} inexistente en cambios_fecha",
                'plaza_id'  => $plazaId,
                'season_id' => $seasonId,
                $campo      => $fechaId,
            ] );

            throw new \InvalidArgumentException(
                "PlazaRepository::{$operacion}(): {$campo} {$fechaId} does not exist in cambios_fecha."
            );
        }

        $fechaSeasonId = (int) $row['season_id'];

        if ( $fechaSeasonId !== $seasonId ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'          => $operacion,
                'motivo'             => "{$campo} pertenece a otra temporada",
                'plaza_id'           => $plazaId,
                'season_id_esperado' => $seasonId,
                'season_id_real'     => $fechaSeasonId,
                $campo               => $fechaId,
            ] );

            throw new \InvalidArgumentException(
                "PlazaRepository::{$operacion}(): {$campo} {$fechaId} belongs to season {$fechaSeasonId}, "
                . "not season {$seasonId}."
            );
        }
    }

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
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'    => 'closeOcupacion',
                'motivo'       => 'cerrada_por invalido',
                'ocupacion_id' => $ocupacionId,
                'cerrada_por'  => $cerradaPor,
            ] );

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
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'    => 'closeOcupacion',
                'motivo'       => 'CAS UPDATE no afecto exactamente 1 fila (concurrencia o ya cerrada)',
                'ocupacion_id' => $ocupacionId,
                'fecha_hasta_id' => $fechaHastaId,
                'cerrada_por'  => $cerradaPor,
                'affected'     => $affected,
                'last_error'   => $wpdb->last_error,
            ] );

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
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'      => 'insertOcupacion',
                'motivo'         => 'insert cambios_ocupacion fallo',
                'plaza_id'       => $plazaId,
                'player_id'      => $playerId,
                'fecha_desde_id' => $fechaDesdeId,
                'last_error'     => $wpdb->last_error,
            ] );

            throw new PlazaPersistenceException( 'insert cambios_ocupacion', $wpdb->last_error );
        }

        $id = (int) $wpdb->insert_id;

        if ( $id <= 0 ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'      => 'insertOcupacion',
                'motivo'         => 'insert cambios_ocupacion devolvio insert_id <= 0',
                'plaza_id'       => $plazaId,
                'player_id'      => $playerId,
                'fecha_desde_id' => $fechaDesdeId,
                'last_error'     => $wpdb->last_error,
            ] );

            throw new PlazaPersistenceException( 'insert cambios_ocupacion', $wpdb->last_error );
        }

        return $id;
    }
}
