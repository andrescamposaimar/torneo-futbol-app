<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes;

use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudPersistenceException;
use EntreRedes\Cambios\Support\OpensTransactions;

/**
 * Encapsulates all wpdb persistence for `cambios_solicitud` — the SOLICITUD
 * DE CAMBIO as a persisted entity with its own lifecycle, as opposed to
 * `Dictamen\SolicitudDeCambio`, which is a pure, ephemeral input DTO the
 * dictamen engine evaluates and never itself stores (see that class's own
 * docblock). This class is what turns "a captain asked for a change" into a
 * row the process owner can act on over several days.
 *
 * *** APROBAR IS NOT PUBLICAR — THE CENTRAL FACT THIS CLASS ENFORCES ***
 * The real operating calendar (see this plugin's README) has the process
 * owner reviewing and approving solicitudes AS THEY ARRIVE, Wednesday
 * through Thursday, but the change is only OFFICIAL once Friday's lote is
 * announced. Until then an `aprobada` solicitud is an INTENTION, still
 * revocable — the process owner can change their mind and move it to
 * `rechazada` or `anulada` before the lote runs. Concretely:
 *
 *   - `aprobar()` NEVER touches `Plazas\PlazaRepository` — it only flips
 *     `estado`. No ocupación closes, no ocupación opens, nothing about who
 *     occupies a plaza changes.
 *   - `publicarLote()` is the ONLY method that calls
 *     `PlazaRepository::succeedOcupacionWithinTransaction()` /
 *     `::closeOcupacionByRegresoTitularWithinTransaction()` — this is where
 *     an approved intention becomes a real change of occupant.
 *
 * *** RE-EVALUATING BEFORE APPLYING ***
 * The dictamen `crear()` stores is a SNAPSHOT of facts true the moment the
 * solicitud was made. Between Wednesday and Friday those facts can go
 * stale: a fecha passed, the entrante got occupied in another plaza, someone
 * else succeeded the very plaza this solicitud targets. `publicarLote()`
 * therefore re-runs `DictamenPipeline::evaluate()` for every solicitud in
 * the lote, fresh, against the CURRENT database, right before applying
 * anything:
 *
 *   - If the fresh dictamen no longer `procede()`, THIS solicitud is not
 *     published, and — because a lote applied halfway would leave nobody
 *     able to tell which of it actually went through — the WHOLE lote is
 *     aborted; nothing in it is applied. The caller finds out which
 *     solicitud failed re-evaluation and why.
 *   - If it still `procede()` but the motivos that produced that answer
 *     changed (e.g. it originally objected and now clears), the solicitud
 *     IS published, and the divergence is recorded in the EventLog — see
 *     `dictamenDivergio()`.
 *
 * The fresh dictamen — not the original one — is what gets stored as
 * `dictamen_aplicado`: when someone later asks "why was this change
 * applied", the honest answer is what was true AT THAT MOMENT, never what
 * was true three days earlier (see `Dictamen\DictamenSnapshot`'s docblock).
 *
 * *** WHY THIS METHOD EXISTS: `PlazaRepository`'s "WithinTransaction" pair ***
 * `publicarLote()` can touch several plazas in one call, and the whole lote
 * must apply as ONE atomic transaction — a lote applied halfway (three
 * plazas changed, a fourth failed) is worse than none applied, because
 * nobody could tell which of it actually went through. `PlazaRepository::
 * succeedOcupacion()` / `::closeOcupacionByRegresoTitular()` each open and
 * close their OWN transaction per call, which is exactly right for a single
 * ad-hoc change but wrong here: a nested `START TRANSACTION` per solicitud
 * would either implicitly commit the outer transaction (real MySQL) or fail
 * outright (the SQLite test shim — see tests/wp-shim.php), either way
 * silently breaking the lote's atomicity. `succeedOcupacionWithinTransaction()`
 * / `closeOcupacionByRegresoTitularWithinTransaction()` exist for exactly
 * this caller: same validation, same write, but assuming an ambient
 * transaction THIS class opens once for the whole lote, and leaving the
 * EventLog record to this class — see those methods' own docblocks.
 *
 * *** OBSERVABILITY ***
 * Same discipline as `PlazaRepository` / `CapitanRepository`: `EventLog` is
 * a mandatory constructor dependency, every successful write records an
 * event after it is durable, every failure is logged before it is thrown.
 *
 * *** cambios_decision — THE APPEND-ONLY DECISION LOG ***
 * `cambios_solicitud.resuelta_por` / `resuelta_at` / `nota` answer "what is
 * the LATEST decision on this solicitud" — see that table's own docblock in
 * `Migrations\InitialSchema`. They are overwritten on every transition, so
 * they cannot answer "what did the FIRST decision say" once a solicitud has
 * been decided more than once (approved Wednesday, rejected Thursday).
 * `transicionar()` and `publicarLote()` therefore ALSO append one row to
 * `cambios_decision` for every decision, in the SAME transaction as the
 * `estado` write — never one without the other (see
 * `insertDecisionWithinTransaction()`'s own docblock).
 *
 * *** GROUPED REQUESTS — `reasignacion_arquero` (0.1.15) ***
 * The process owner was explicit about two things at once: a team's
 * goalkeeper may be replaced by one of the team's own field titulares, but
 * ONLY if, in the SAME decision, an outside player fills the field plaza
 * that titular leaves behind — "deberíamos hacer que el pedido se agrupe
 * uno solo y que la aprobación sea en grupo" — and separately, "todo cambio
 * necesita aprobación". So this is ONE `cambios_solicitud` row carrying TWO
 * movements, approved/rejected/published as a unit:
 *
 *   - Movement 1 (the goal plaza): the current goalkeeper is succeeded by
 *     the field titular. The goal plaza's techo does NOT apply to him when
 *     `Calendario\Settings::exencionArcoActiva()` is on — goalkeeping is a
 *     different skill, not a higher-scoring substitute position (see
 *     `Dictamen\Reglas\PuntajeDentroDelTecho`'s own docblock).
 *   - Movement 2 (the vacated field plaza): an outside player fills it,
 *     under the ORDINARY rules — no exemption, the plaza's own techo
 *     applies (already equal to that titular's puntaje, since a plaza's
 *     techo is snapshotted from its titular at `PlazaRepository::openPlaza()`
 *     time), the player must not already occupy a plaza, must not be a
 *     goalkeeper, and so on. Nothing new was added to the ten-rule ruleset
 *     for this movement — see `Dictamen\DictamenPipeline::evaluateGrupo()`'s
 *     own docblock for why running the SAME ruleset twice, once per
 *     movement, and unioning the motivos, is enough.
 *
 * `crearReasignacionArquero()` builds both movements as ordinary
 * `Dictamen\SolicitudDeCambio::sustitucion()` instances, evaluates them
 * together via `evaluateGrupo()`, and persists ONE row — `plaza_id` /
 * `entrante_player_id` / `saliente_player_id` for movement 1 (reusing the
 * columns every other tipo already has), `plaza_campo_id` /
 * `entrante_campo_player_id` for movement 2 (new in 0.1.15 — see
 * `Migrations\InitialSchema::sqlCambiosSolicitud()`'s own docblock for why
 * `plaza_campo_id` is snapshotted rather than re-derived later).
 *
 * `publicarLote()` applies BOTH movements inside its one ambient
 * transaction, in a specific order that matters: movement 2 (vacate the
 * field plaza, install the outside player) runs BEFORE movement 1 (vacate
 * the goal plaza, install the titular there). Doing it in the OPPOSITE
 * order would, for one write in the middle of the transaction, have the
 * titular occupying BOTH the goal plaza and his old field plaza at once —
 * an intermediate state this class never allows to exist, not even
 * uncommitted. Movement-2-first means the titular instead passes through
 * occupying ZERO plazas for that one intermediate write, which is always
 * safe. Both writes land in the SAME transaction as every other solicitud in
 * the lote, so a failure in either movement — or in any OTHER solicitud in
 * the same lote — rolls back everything, exactly like `publicarLote()`'s
 * class docblock already promises for the lote as a whole.
 */
class SolicitudRepository {

    use OpensTransactions;

    private \wpdb $wpdb;
    private PlazaRepository $plazaRepository;
    private DictamenPipeline $dictamenPipeline;
    private EventLog $eventLog;

    public function __construct(
        \wpdb $wpdb,
        PlazaRepository $plazaRepository,
        DictamenPipeline $dictamenPipeline,
        EventLog $eventLog
    ) {
        $this->wpdb             = $wpdb;
        $this->plazaRepository  = $plazaRepository;
        $this->dictamenPipeline = $dictamenPipeline;
        $this->eventLog         = $eventLog;
    }

    /**
     * Persists a brand-new solicitud, `pendiente`, with $dictamen frozen as
     * its `dictamen_original` snapshot. Does NOT gate on `$dictamen->procede()`
     * — per the reglamento the process owner reviews and decides on every
     * solicitud regardless of what the dictamen says (see `Dictamen\Dictamen`'s
     * own docblock: "a dictamen is not an approval"), so a solicitud whose
     * dictamen objects is stored exactly the same way as one that clears
     * every rule; only `aprobar()` / `rechazar()` decide anything.
     *
     * *** WHY THE SALIENTE IS CAPTURED HERE, NOT DERIVED LATER ***
     * `saliente_player_id` is resolved from `PlazaRepository::findOcupacionVigente()`
     * ONCE, right here, and frozen into the row — see
     * `Migrations\InitialSchema::sqlCambiosSolicitud()`'s own docblock for
     * the full reasoning (a plaza's vigent occupant changes over time, so
     * reading it later would silently relabel an old solicitud with today's
     * occupant instead of the one who actually left). A plaza with no vigent
     * ocupación at this exact instant (should not happen once
     * `PlazaRepository::openPlaza()` has run, but this method does not
     * assume it) stores `NULL`, never a guess.
     *
     * @throws SolicitudPersistenceException When the insert fails at the
     *         wpdb level.
     */
    public function crear( SolicitudDeCambio $solicitud, int $solicitadaPor, Dictamen $dictamen, string $now ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $snapshot = DictamenSnapshot::fromDictamen( $dictamen, $now );

        $vigente           = $this->plazaRepository->findOcupacionVigente( $solicitud->plazaId() );
        $salientePlayerId  = null !== $vigente ? (int) $vigente['player_id'] : null;

        $result = $wpdb->insert(
            $p . 'cambios_solicitud',
            [
                'season_id'                => $solicitud->seasonId(),
                'team_id'                  => $solicitud->teamId(),
                'plaza_id'                 => $solicitud->plazaId(),
                'tipo'                     => $solicitud->tipo(),
                'entrante_player_id'       => $solicitud->entrantePlayerId(),
                'saliente_player_id'       => $salientePlayerId,
                'fecha_id'                 => $solicitud->fechaId(),
                'solicitada_por'           => $solicitadaPor,
                'solicitada_at'            => $now,
                'solicitud_instante_epoch' => $solicitud->instanteEpoch(),
                'dictamen_original'        => $snapshot->toJson(),
                'dictamen_aplicado'        => null,
                'estado'                   => EstadoSolicitud::PENDIENTE,
                'resuelta_por'             => null,
                'resuelta_at'              => null,
                'nota'                     => null,
                'created_at'               => $now,
                'updated_at'               => $now,
            ]
        );

        if ( false === $result ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'crear',
                'motivo'     => 'insert cambios_solicitud fallo',
                'season_id'  => $solicitud->seasonId(),
                'team_id'    => $solicitud->teamId(),
                'plaza_id'   => $solicitud->plazaId(),
                'last_error' => $wpdb->last_error,
            ] );

            throw new SolicitudPersistenceException( 'insert cambios_solicitud', $wpdb->last_error );
        }

        $id = (int) $wpdb->insert_id;

        if ( $id <= 0 ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'crear',
                'motivo'     => 'insert cambios_solicitud devolvio insert_id <= 0',
                'season_id'  => $solicitud->seasonId(),
                'team_id'    => $solicitud->teamId(),
                'plaza_id'   => $solicitud->plazaId(),
                'last_error' => $wpdb->last_error,
            ] );

            throw new SolicitudPersistenceException( 'insert cambios_solicitud', $wpdb->last_error );
        }

        $this->eventLog->record( 'solicitud.creada', [
            'solicitud_id'       => $id,
            'season_id'          => $solicitud->seasonId(),
            'team_id'            => $solicitud->teamId(),
            'plaza_id'           => $solicitud->plazaId(),
            'tipo'               => $solicitud->tipo(),
            'entrante_player_id' => $solicitud->entrantePlayerId(),
            'saliente_player_id' => $salientePlayerId,
            'fecha_id'           => $solicitud->fechaId(),
            'solicitada_por'     => $solicitadaPor,
            'dictamen_procede'   => $dictamen->procede(),
            'dictamen_motivos'   => $snapshot->motivoCodigos(),
        ] );

        return $id;
    }

    /**
     * Persists a brand-new GROUPED goalkeeper reassignment — see class
     * docblock, "GROUPED REQUESTS". Builds both movements as ordinary
     * `Dictamen\SolicitudDeCambio::sustitucion()` instances, evaluates them
     * together via `DictamenPipeline::evaluateGrupo()`, and inserts ONE
     * `cambios_solicitud` row, `pendiente`, with the UNIONED dictamen frozen
     * as `dictamen_original` — same "the dictamen is never an approval"
     * discipline as `crear()`: a solicitud whose unioned dictamen objects is
     * still persisted, never rejected at creation time.
     *
     * @param int $plazaArcoId        The goal plaza (`cambios_plaza.es_arco = 1`).
     * @param int $titularPlayerId    The field titular moving into goal —
     *        movement 1's entrante.
     * @param int $plazaCampoId       The field plaza that titular leaves
     *        behind (`cambios_plaza.es_arco = 0`) — snapshotted here, see
     *        `Migrations\InitialSchema::sqlCambiosSolicitud()`'s own
     *        docblock for why this is never re-derived later from "whichever
     *        plaza the titular currently occupies".
     * @param int $entranteCampoPlayerId The outside player filling
     *        `$plazaCampoId` — movement 2's entrante.
     * @throws \InvalidArgumentException When `$plazaArcoId` is not the
     *         goalkeeper's plaza, `$plazaCampoId` IS the goalkeeper's plaza,
     *         or either plaza does not belong to `$teamId`/`$seasonId` — a
     *         structural precondition of this tipo, checked here rather than
     *         left to surface as a confusing dictamen motivo later. This is
     *         NOT a business objection a Regla reports (compare
     *         `Dictamen\DictamenContextAssembler::assemble()`'s own
     *         \RuntimeException for "the plaza does not exist" — same
     *         category of failure, a malformed request, not a reglamento
     *         violation).
     * @throws SolicitudPersistenceException When the insert fails at the
     *         wpdb level.
     */
    public function crearReasignacionArquero(
        int $seasonId,
        int $teamId,
        int $plazaArcoId,
        int $titularPlayerId,
        int $plazaCampoId,
        int $entranteCampoPlayerId,
        int $fechaId,
        int $instanteEpoch,
        int $solicitadaPor,
        string $now
    ): int {
        $this->assertPlazasDeReasignacionArquero( $plazaArcoId, $plazaCampoId, $teamId, $seasonId );

        $legArco  = SolicitudDeCambio::sustitucion( $seasonId, $teamId, $plazaArcoId, $titularPlayerId, $fechaId, $instanteEpoch );
        $legCampo = SolicitudDeCambio::sustitucion( $seasonId, $teamId, $plazaCampoId, $entranteCampoPlayerId, $fechaId, $instanteEpoch );

        $dictamen = $this->dictamenPipeline->evaluateGrupo( $legArco, $legCampo );
        $snapshot = DictamenSnapshot::fromDictamen( $dictamen, $now );

        $vigenteArco      = $this->plazaRepository->findOcupacionVigente( $plazaArcoId );
        $salientePlayerId = null !== $vigenteArco ? (int) $vigenteArco['player_id'] : null;

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->insert(
            $p . 'cambios_solicitud',
            [
                'season_id'                => $seasonId,
                'team_id'                  => $teamId,
                'plaza_id'                 => $plazaArcoId,
                'tipo'                     => SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO,
                'entrante_player_id'       => $titularPlayerId,
                'saliente_player_id'       => $salientePlayerId,
                'plaza_campo_id'           => $plazaCampoId,
                'entrante_campo_player_id' => $entranteCampoPlayerId,
                'fecha_id'                 => $fechaId,
                'solicitada_por'           => $solicitadaPor,
                'solicitada_at'            => $now,
                'solicitud_instante_epoch' => $instanteEpoch,
                'dictamen_original'        => $snapshot->toJson(),
                'dictamen_aplicado'        => null,
                'estado'                   => EstadoSolicitud::PENDIENTE,
                'resuelta_por'             => null,
                'resuelta_at'              => null,
                'nota'                     => null,
                'created_at'               => $now,
                'updated_at'               => $now,
            ]
        );

        if ( false === $result ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'      => 'crearReasignacionArquero',
                'motivo'         => 'insert cambios_solicitud fallo',
                'season_id'      => $seasonId,
                'team_id'        => $teamId,
                'plaza_arco_id'  => $plazaArcoId,
                'plaza_campo_id' => $plazaCampoId,
                'last_error'     => $wpdb->last_error,
            ] );

            throw new SolicitudPersistenceException( 'insert cambios_solicitud', $wpdb->last_error );
        }

        $id = (int) $wpdb->insert_id;

        if ( $id <= 0 ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'  => 'crearReasignacionArquero',
                'motivo'     => 'insert cambios_solicitud devolvio insert_id <= 0',
                'season_id'  => $seasonId,
                'team_id'    => $teamId,
                'last_error' => $wpdb->last_error,
            ] );

            throw new SolicitudPersistenceException( 'insert cambios_solicitud', $wpdb->last_error );
        }

        $this->eventLog->record( 'solicitud.creada', [
            'solicitud_id'             => $id,
            'season_id'                => $seasonId,
            'team_id'                  => $teamId,
            'plaza_id'                 => $plazaArcoId,
            'tipo'                     => SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO,
            'entrante_player_id'       => $titularPlayerId,
            'saliente_player_id'       => $salientePlayerId,
            'plaza_campo_id'           => $plazaCampoId,
            'entrante_campo_player_id' => $entranteCampoPlayerId,
            'fecha_id'                 => $fechaId,
            'solicitada_por'           => $solicitadaPor,
            'dictamen_procede'         => $dictamen->procede(),
            'dictamen_motivos'         => $snapshot->motivoCodigos(),
        ] );

        return $id;
    }

    /**
     * *** WHY A FAILED READ HERE STILL READS AS "NOT FOUND" *** Every current
     * caller (`transicionar()` — i.e. `aprobar()`/`rechazar()`/`anular()` —
     * and `publicarLote()`'s pre-flight loop) treats a `null` return as a
     * reason to REFUSE: `transicionar()` throws `\RuntimeException`, and
     * `publicarLote()` aborts the whole lote via `abortarLote()`. Neither
     * ever treats "not found" as permission to proceed, so misreading a
     * wpdb-level failure as "not found" here can only ever produce a
     * wrongful denial — same reasoning as
     * `Capitania\CapitanRepository::findCapitanVigente()` — and this read is
     * deliberately NOT routed through `assertReadSucceeded()`.
     *
     * @return array<string, mixed>|null
     */
    public function findSolicitud( int $id ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$p}cambios_solicitud WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        return empty( $row ) ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function listPendientes( int $seasonId ): array {
        return $this->listByEstado( $seasonId, EstadoSolicitud::PENDIENTE );
    }

    /** @return array<int, array<string, mixed>> */
    public function listAprobadas( int $seasonId ): array {
        return $this->listByEstado( $seasonId, EstadoSolicitud::APROBADA );
    }

    /**
     * EVERY solicitud of ($seasonId, $teamId), in ANY estado, ordered the
     * same way as listByEstado() — this is what a captain's own request tray
     * needs (slice 4d's `GET /cambios/solicitudes`): unlike listPendientes()/
     * listAprobadas(), which exist for the PROCESS OWNER's tray and are
     * therefore scoped to one estado at a time, a captain wants to see every
     * solicitud they have ever made for their team, whatever happened to it
     * since.
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level —
     *         same "READ FAILURES MUST NEVER READ AS 'NO ROWS'" discipline as
     *         listByEstado().
     */
    public function listByEquipo( int $seasonId, int $teamId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_solicitud
                  WHERE season_id = %d AND team_id = %d
                  ORDER BY solicitada_at ASC, id ASC",
                $seasonId,
                $teamId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'listByEquipo', [ 'season_id' => $seasonId, 'team_id' => $teamId ] );

        return $rows;
    }

    /**
     * Flips a `pendiente` (or still-`aprobada`) solicitud to `aprobada` —
     * see class docblock, "APROBAR IS NOT PUBLICAR": this NEVER touches
     * `PlazaRepository`. No ocupación is opened or closed here.
     *
     * @param string $decididaPorNombre The acting user's display name (or
     *        login), captured NOW and frozen into `cambios_decision` — see
     *        class docblock, "cambios_decision". Never re-derived from
     *        `wp_users` later, so a renamed or deleted WP user never rewrites
     *        history.
     * @throws \RuntimeException When $id does not exist.
     * @throws Exception\TransicionInvalidaException When the solicitud's
     *         current estado cannot move to `aprobada`.
     * @throws SolicitudPersistenceException When the update fails at the
     *         wpdb level.
     */
    public function aprobar( int $id, int $resueltaPor, ?string $nota, string $now, string $decididaPorNombre ): void {
        $this->transicionar( $id, EstadoSolicitud::APROBADA, $resueltaPor, $nota, $now, $decididaPorNombre, 'solicitud.aprobada' );
    }

    /**
     * @param string $decididaPorNombre See `aprobar()`'s own docblock.
     * @throws \RuntimeException When $id does not exist.
     * @throws Exception\TransicionInvalidaException When the solicitud's
     *         current estado cannot move to `rechazada`.
     * @throws SolicitudPersistenceException When the update fails at the
     *         wpdb level.
     */
    public function rechazar( int $id, int $resueltaPor, ?string $nota, string $now, string $decididaPorNombre ): void {
        $this->transicionar( $id, EstadoSolicitud::RECHAZADA, $resueltaPor, $nota, $now, $decididaPorNombre, 'solicitud.rechazada' );
    }

    /**
     * @param string $decididaPorNombre See `aprobar()`'s own docblock.
     * @throws \RuntimeException When $id does not exist.
     * @throws Exception\TransicionInvalidaException When the solicitud's
     *         current estado cannot move to `anulada`.
     * @throws SolicitudPersistenceException When the update fails at the
     *         wpdb level.
     */
    public function anular( int $id, int $resueltaPor, ?string $nota, string $now, string $decididaPorNombre ): void {
        $this->transicionar( $id, EstadoSolicitud::ANULADA, $resueltaPor, $nota, $now, $decididaPorNombre, 'solicitud.anulada' );
    }

    /**
     * The complete history of decisions made on $solicitudId, chronological
     * — see class docblock, "cambios_decision". Unlike `findSolicitud()`'s
     * `resuelta_por`/`resuelta_at`/`nota` (the LATEST decision only), this
     * returns every row ever appended, oldest first.
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level —
     *         same "READ FAILURES MUST NEVER READ AS 'NO ROWS'" discipline as
     *         `listByEstado()`.
     */
    public function listDecisiones( int $solicitudId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_decision
                  WHERE solicitud_id = %d
                  ORDER BY decidida_at ASC, id ASC",
                $solicitudId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'listDecisiones', [ 'solicitud_id' => $solicitudId ] );

        return $rows;
    }

    /**
     * The Friday lote announcement: re-evaluates every $ids solicitud's
     * dictamen fresh, and — only if EVERY one of them still `procede()` —
     * applies all of them as ONE atomic transaction (see class docblock,
     * "RE-EVALUATING BEFORE APPLYING" and "WHY THIS METHOD EXISTS").
     *
     * Every $ids entry MUST currently be `aprobada` — that is the only
     * transition this method performs (`aprobada` → `publicada`); a
     * solicitud in any other estado aborts the WHOLE lote, same as a
     * solicitud whose fresh dictamen no longer procede.
     *
     * *** THIS METHOD DOES NOT AUTHORIZE THE CALLER ***
     * Exactly like `undoLastOcupacion()` / `closePlaza()` below: this is one
     * of the most dangerous entry points in this plugin to expose — it
     * writes real occupancy changes over real rosters for an entire lote at
     * once. It performs NO role or ownership check of its own. A future REST
     * wrapper MUST verify the caller's role (process owner, never a captain)
     * BEFORE invoking this — never rely on this method to reject an
     * unauthorized caller, because it will not.
     *
     * @param int[] $ids
     * @return array{
     *     publicadas: int[],
     *     no_publicadas: int[],
     *     abortado: bool,
     *     motivo: string|null,
     *     divergencias: int[],
     *     culprit_id: int|null
     * } `publicadas` — solicitud ids actually applied and moved to
     *   `publicada`. `no_publicadas` — every id that did NOT get applied
     *   (all of $ids when `abortado` is true, empty when the lote fully
     *   succeeded). `abortado` — true when nothing in this call was
     *   applied. `motivo` — human-readable reason, only set when aborted.
     *   `divergencias` — ids that WERE published but whose fresh dictamen's
     *   motivos differ from the original snapshot (see
     *   `dictamenDivergio()`) — informational, not a failure. `culprit_id` —
     *   the ONE solicitud id responsible for the abort (the one that failed
     *   validation/re-evaluation, or the one mid-write when a lote-wide
     *   failure hit), as a plain field a UI can highlight directly — never
     *   null when `abortado` is true, always null otherwise. `motivo`
     *   remains the human-readable sentence; this is its machine-readable
     *   twin, so a caller never has to parse Spanish prose to find the id.
     *
     * @param string $decididaPorNombre See `aprobar()`'s own docblock — ONE
     *        name for the whole lote, since a lote publication is a single
     *        process-owner action; `insertDecisionWithinTransaction()` still
     *        appends one `cambios_decision` row PER solicitud published, each
     *        stamped with this same name.
     */
    public function publicarLote( array $ids, int $resueltaPor, string $now, string $decididaPorNombre ): array {
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

        if ( empty( $ids ) ) {
            return [
                'publicadas'     => [],
                'no_publicadas'  => [],
                'abortado'       => false,
                'motivo'         => null,
                'divergencias'   => [],
                'culprit_id'     => null,
            ];
        }

        // ─── Pre-flight: load, validate transition, re-evaluate — no writes yet. ───

        $prepared  = [];
        $seasonIds = [];

        foreach ( $ids as $id ) {
            $row = $this->findSolicitud( $id );

            if ( null === $row ) {
                return $this->abortarLote( $ids, "la solicitud {$id} no existe", $id );
            }

            if ( ! EstadoSolicitud::esTransicionValida( (string) $row['estado'], EstadoSolicitud::PUBLICADA ) ) {
                return $this->abortarLote(
                    $ids,
                    "la solicitud {$id} no puede publicarse desde su estado actual ('{$row['estado']}') — "
                        . 'debe estar aprobada.',
                    $id
                );
            }

            $seasonIds[ $id ] = (int) $row['season_id'];

            // Both the fresh re-evaluation AND the parse of the ORIGINAL
            // snapshot live in the same try/catch on purpose: a corrupt
            // `dictamen_original` (bad JSON from a data problem elsewhere)
            // must abort THIS lote with an explicit motivo and an EventLog
            // event — exactly like a re-evaluation failure — never escape as
            // a raw, unlogged exception that takes the whole request down.
            //
            // A `reasignacion_arquero` row re-runs BOTH movements fresh via
            // `evaluateGrupo()` — see class docblock, "GROUPED REQUESTS" —
            // so a grouped request that no longer holds (either movement)
            // aborts the whole lote exactly like any other stale solicitud.
            try {
                if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $row['tipo'] ) {
                    [ $legArco, $legCampo ] = $this->reconstruirLegsGrupo( $row );
                    $dictamenFresco          = $this->dictamenPipeline->evaluateGrupo( $legArco, $legCampo );
                } else {
                    $solicitudObj   = $this->reconstruirSolicitud( $row );
                    $dictamenFresco = $this->dictamenPipeline->evaluate( $solicitudObj );
                }

                $original = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );
            } catch ( \Throwable $e ) {
                return $this->abortarLote(
                    $ids,
                    "la solicitud {$id} no pudo prepararse para publicarse (re-evaluacion o "
                        . 'dictamen_original invalido): ' . $e->getMessage(),
                    $id
                );
            }

            if ( ! $dictamenFresco->procede() ) {
                $motivos = implode( ', ', array_map( static fn ( $m ) => $m->codigo(), $dictamenFresco->motivos() ) );

                return $this->abortarLote(
                    $ids,
                    "la solicitud {$id} ya no procede al momento de publicar el lote (motivos: {$motivos})",
                    $id
                );
            }

            $prepared[ $id ] = [
                'row'       => $row,
                'dictamen'  => $dictamenFresco,
                'divergio'  => $this->dictamenDivergio( $original, $dictamenFresco ),
            ];
        }

        // A lote spanning more than one season is not exploitable TODAY (the
        // captain/process-owner role is unique and global — see
        // Capitania\CapitanAuthorizer), but this project already has
        // scoped-by-season authorization elsewhere, and the day this becomes
        // per-season, a lote silently mixing seasons would be exactly the
        // kind of gap that sits quiet until someone finds it. Fail loud now,
        // while it costs nothing to check.
        if ( count( array_unique( $seasonIds ) ) > 1 ) {
            return $this->abortarLote(
                $ids,
                'las solicitudes del lote pertenecen a mas de una temporada ('
                    . implode( ', ', array_unique( $seasonIds ) ) . ') — un lote debe publicarse por temporada.'
            );
        }

        // ─── Apply — every write below runs inside ONE transaction. ───

        $this->beginTransaction( __FUNCTION__ );

        $ultimoIdIntentado = null;

        try {
            foreach ( $ids as $id ) {
                $ultimoIdIntentado = $id;

                $row     = $prepared[ $id ]['row'];
                $plazaId = (int) $row['plaza_id'];
                $fechaId = (int) $row['fecha_id'];

                if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $row['tipo'] ) {
                    $plazaCampoId = (int) $row['plaza_campo_id'];

                    // Movement 2 FIRST — see class docblock, "GROUPED
                    // REQUESTS", for why this order (never the reverse) is
                    // what keeps the titular from ever occupying two plazas
                    // at once, even momentarily inside this uncommitted
                    // transaction: this closes his OWN field-plaza
                    // occupation and installs the outside player, so by the
                    // time movement 1 opens his goal-plaza occupation below
                    // he already occupies zero plazas, not two.
                    $ocupacionCampoId = $this->plazaRepository->succeedOcupacionWithinTransaction(
                        $plazaCampoId,
                        (int) $row['entrante_campo_player_id'],
                        $fechaId,
                        'reemplazada',
                        $now
                    );

                    $ocupacionId = $this->plazaRepository->succeedOcupacionWithinTransaction(
                        $plazaId,
                        (int) $row['entrante_player_id'],
                        $fechaId,
                        'reemplazada',
                        $now
                    );

                    $this->marcarPublicadaWithinTransaction( $id, $resueltaPor, $now, $prepared[ $id ]['dictamen'], $ocupacionId, $ocupacionCampoId );
                } elseif ( SolicitudDeCambio::TIPO_SUSTITUCION === $row['tipo'] ) {
                    $ocupacionId = $this->plazaRepository->succeedOcupacionWithinTransaction(
                        $plazaId,
                        (int) $row['entrante_player_id'],
                        $fechaId,
                        'reemplazada',
                        $now
                    );

                    $this->marcarPublicadaWithinTransaction( $id, $resueltaPor, $now, $prepared[ $id ]['dictamen'], $ocupacionId );
                } else {
                    $ocupacionId = $this->plazaRepository->closeOcupacionByRegresoTitularWithinTransaction( $plazaId, $fechaId, $now );

                    $this->marcarPublicadaWithinTransaction( $id, $resueltaPor, $now, $prepared[ $id ]['dictamen'], $ocupacionId );
                }

                // One cambios_decision row PER solicitud published — see
                // class docblock, "cambios_decision" — inside this SAME
                // ambient transaction, so a lote that rolls back below takes
                // every decision row it just appended down with it too.
                $this->insertDecisionWithinTransaction( $id, EstadoSolicitud::PUBLICADA, $resueltaPor, $decididaPorNombre, null, $now );
            }
        } catch ( \Throwable $e ) {
            // rollbackTransaction() itself throws Support\Exception\InconsistentStateException
            // — instead of returning a tidy "abortado" array — when the
            // ROLLBACK fails too: at that point the database state is
            // genuinely unknown, and returning "nothing was applied" would
            // be exactly as false as returning "everything was applied".
            $this->rollbackTransaction( __FUNCTION__, $e, [ 'ids' => $ids ] );

            $this->eventLog->record( 'solicitud.lote_abortado', [
                'ids'                 => $ids,
                'motivo'              => 'escritura fallida a mitad del lote: ' . $e->getMessage(),
                'ultima_id_intentada' => $ultimoIdIntentado,
                'culprit_id'          => $ultimoIdIntentado,
            ] );

            return [
                'publicadas'    => [],
                'no_publicadas' => $ids,
                'abortado'      => true,
                'motivo'        => 'escritura fallida a mitad del lote (id ' . $ultimoIdIntentado . '): ' . $e->getMessage(),
                'divergencias'  => [],
                'culprit_id'    => $ultimoIdIntentado,
            ];
        }

        // Outside the try/catch above ON PURPOSE: a COMMIT that fails must
        // never be caught by the same handler that reports "abortado" —
        // commitTransaction() itself throws instead (see
        // Support\OpensTransactions), so the caller can never mistake an
        // unconfirmed COMMIT for either a successful publish or a clean
        // abort.
        $this->commitTransaction( __FUNCTION__, [ 'ids' => $ids ] );

        $divergencias = [];

        foreach ( $ids as $id ) {
            $row      = $prepared[ $id ]['row'];
            $divergio = $prepared[ $id ]['divergio'];

            if ( $divergio ) {
                $divergencias[] = $id;
            }

            $this->eventLog->record( 'solicitud.publicada', [
                'solicitud_id' => $id,
                'season_id'    => (int) $row['season_id'],
                'team_id'      => (int) $row['team_id'],
                'plaza_id'     => (int) $row['plaza_id'],
                'tipo'         => $row['tipo'],
                'resuelta_por' => $resueltaPor,
                'divergio'     => $divergio,
            ] );
        }

        return [
            'publicadas'    => $ids,
            'no_publicadas' => [],
            'abortado'      => false,
            'motivo'        => null,
            'divergencias'  => $divergencias,
            'culprit_id'    => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Shared body of aprobar() / rechazar() / anular(): find, validate the
     * transition through `EstadoSolicitud`, write the `estado` change AND
     * append its `cambios_decision` row in ONE transaction, log.
     *
     * *** WHY A TRANSACTION FOR A SINGLE-ROW UPDATE ***
     * The `cambios_solicitud` UPDATE alone would be atomic on its own — but
     * this method also writes a SECOND row, to `cambios_decision`, and the
     * two must land together or not at all (see class docblock,
     * "cambios_decision"): a state flipped to `aprobada` with no decision
     * row would make "who approved this" unanswerable, and a decision row
     * for a state change that never actually committed would be a
     * fabricated record of something that did not happen. Same reasoning as
     * `publicarLote()`'s own transaction, at one-tenth the size.
     */
    private function transicionar( int $id, string $nuevoEstado, int $resueltaPor, ?string $nota, string $now, string $decididaPorNombre, string $evento ): void {
        $row = $this->findSolicitud( $id );

        if ( null === $row ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion' => $evento,
                'motivo'    => 'la solicitud no existe',
                'solicitud_id' => $id,
            ] );

            throw new \RuntimeException( "SolicitudRepository: solicitud {$id} does not exist." );
        }

        EstadoSolicitud::assertTransicionValida( (string) $row['estado'], $nuevoEstado );

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $this->beginTransaction( $evento, [ 'solicitud_id' => $id ] );

        try {
            $result = $wpdb->update(
                $p . 'cambios_solicitud',
                [
                    'estado'       => $nuevoEstado,
                    'resuelta_por' => $resueltaPor,
                    'resuelta_at'  => $now,
                    'nota'         => $nota,
                    'updated_at'   => $now,
                ],
                [ 'id' => $id ]
            );

            if ( false === $result ) {
                throw new SolicitudPersistenceException( "update cambios_solicitud (estado={$nuevoEstado})", $wpdb->last_error );
            }

            $this->insertDecisionWithinTransaction( $id, $nuevoEstado, $resueltaPor, $decididaPorNombre, $nota, $now );
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( $evento, $e, [ 'solicitud_id' => $id ] );

            $this->eventLog->record( 'escritura.fallida', [
                'operacion'    => $evento,
                'motivo'       => 'transicion de estado o registro de decision fallo: ' . $e->getMessage(),
                'solicitud_id' => $id,
                'last_error'   => $wpdb->last_error,
            ] );

            throw $e;
        }

        $this->commitTransaction( $evento, [ 'solicitud_id' => $id ] );

        $this->eventLog->record( $evento, [
            'solicitud_id'        => $id,
            'estado_anterior'     => $row['estado'],
            'estado_nuevo'        => $nuevoEstado,
            'resuelta_por'        => $resueltaPor,
            'decidida_por_nombre' => $decididaPorNombre,
            'nota'                => $nota,
        ] );
    }

    /**
     * Appends ONE row to `cambios_decision` for a single decision on
     * $solicitudId — see class docblock, "cambios_decision". Writes WITHOUT
     * wrapping its own transaction or logging its own event — same contract
     * as `PlazaRepository`'s "WithinTransaction" methods (and this class's own
     * `marcarPublicadaWithinTransaction()`): the caller (`transicionar()`,
     * `publicarLote()`) owns the ambient transaction and its own EventLog
     * event, once, after that transaction actually commits.
     *
     * @throws SolicitudPersistenceException When the insert fails at the
     *         wpdb level.
     */
    private function insertDecisionWithinTransaction(
        int $solicitudId,
        string $accion,
        int $decididaPor,
        string $decididaPorNombre,
        ?string $nota,
        string $now
    ): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->insert(
            $p . 'cambios_decision',
            [
                'solicitud_id'        => $solicitudId,
                'accion'              => $accion,
                'decidida_por'        => $decididaPor,
                'decidida_por_nombre' => $decididaPorNombre,
                'decidida_at'         => $now,
                'nota'                => $nota,
            ]
        );

        if ( false === $result ) {
            throw new SolicitudPersistenceException( 'insert cambios_decision', $wpdb->last_error );
        }
    }

    /**
     * Writes the `publicada` transition WITHOUT wrapping its own
     * transaction or logging its own event — same contract as
     * `PlazaRepository`'s "WithinTransaction" methods, for the same reason:
     * this runs inside `publicarLote()`'s single outer transaction, and
     * `publicarLote()` logs `solicitud.publicada` itself once the whole
     * lote's COMMIT has actually succeeded.
     *
     * `$ocupacionId` is the id `succeedOcupacionWithinTransaction()` /
     * `closeOcupacionByRegresoTitularWithinTransaction()` just returned for
     * THIS solicitud (movement 1, for a `reasignacion_arquero`) — persisted
     * here so undoing a badly-published lote never again requires
     * cross-referencing the EventLog by `plaza_id` and timestamp by hand.
     * `$ocupacionCampoId` is the SAME thing for movement 2 — null for every
     * other tipo, which has no second movement.
     *
     * @throws SolicitudPersistenceException When the update fails at the
     *         wpdb level.
     */
    private function marcarPublicadaWithinTransaction( int $id, int $resueltaPor, string $now, Dictamen $dictamenAplicado, int $ocupacionId, ?int $ocupacionCampoId = null ): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $result = $wpdb->update(
            $p . 'cambios_solicitud',
            [
                'estado'             => EstadoSolicitud::PUBLICADA,
                'resuelta_por'       => $resueltaPor,
                'resuelta_at'        => $now,
                'dictamen_aplicado'  => DictamenSnapshot::fromDictamen( $dictamenAplicado, $now )->toJson(),
                'ocupacion_id'       => $ocupacionId,
                'ocupacion_campo_id' => $ocupacionCampoId,
                'updated_at'         => $now,
            ],
            [ 'id' => $id ]
        );

        if ( false === $result ) {
            $this->eventLog->record( 'escritura.fallida', [
                'operacion'    => 'publicarLote',
                'motivo'       => 'update cambios_solicitud (publicada) fallo',
                'solicitud_id' => $id,
                'last_error'   => $wpdb->last_error,
            ] );

            throw new SolicitudPersistenceException( 'update cambios_solicitud (publicada)', $wpdb->last_error );
        }
    }

    /**
     * Rebuilds the `Dictamen\SolicitudDeCambio` a stored row originally
     * came from, so `publicarLote()` can hand it back to `DictamenPipeline`.
     * Uses `solicitud_instante_epoch`, NEVER a value derived from
     * `solicitada_at` — see `Migrations\InitialSchema::sqlCambiosSolicitud()`'s
     * docblock for why.
     *
     * @param array<string, mixed> $row
     */
    private function reconstruirSolicitud( array $row ): SolicitudDeCambio {
        if ( SolicitudDeCambio::TIPO_SUSTITUCION === $row['tipo'] ) {
            return SolicitudDeCambio::sustitucion(
                (int) $row['season_id'],
                (int) $row['team_id'],
                (int) $row['plaza_id'],
                (int) $row['entrante_player_id'],
                (int) $row['fecha_id'],
                (int) $row['solicitud_instante_epoch']
            );
        }

        return SolicitudDeCambio::regreso(
            (int) $row['season_id'],
            (int) $row['team_id'],
            (int) $row['plaza_id'],
            (int) $row['fecha_id'],
            (int) $row['solicitud_instante_epoch']
        );
    }

    /**
     * Same job as `reconstruirSolicitud()` above, but for a `reasignacion_arquero`
     * row: rebuilds BOTH movements as ordinary `Dictamen\SolicitudDeCambio::sustitucion()`
     * instances from the columns `crearReasignacionArquero()` snapshotted —
     * `plaza_campo_id` / `entrante_campo_player_id` for movement 2, never
     * re-derived from "whichever plaza the titular currently occupies" (see
     * `Migrations\InitialSchema::sqlCambiosSolicitud()`'s own docblock).
     *
     * @param array<string, mixed> $row
     * @return array{0: SolicitudDeCambio, 1: SolicitudDeCambio} [$legArco, $legCampo]
     */
    private function reconstruirLegsGrupo( array $row ): array {
        $legArco = SolicitudDeCambio::sustitucion(
            (int) $row['season_id'],
            (int) $row['team_id'],
            (int) $row['plaza_id'],
            (int) $row['entrante_player_id'],
            (int) $row['fecha_id'],
            (int) $row['solicitud_instante_epoch']
        );

        $legCampo = SolicitudDeCambio::sustitucion(
            (int) $row['season_id'],
            (int) $row['team_id'],
            (int) $row['plaza_campo_id'],
            (int) $row['entrante_campo_player_id'],
            (int) $row['fecha_id'],
            (int) $row['solicitud_instante_epoch']
        );

        return [ $legArco, $legCampo ];
    }

    /**
     * Structural preconditions of a `reasignacion_arquero` request — checked
     * BEFORE the dictamen ever runs, same category of failure as
     * `Dictamen\DictamenContextAssembler::assemble()`'s "the plaza does not
     * exist" \RuntimeException: a malformed request, never a reglamento
     * objection a `Regla` should report as a Motivo.
     *
     * @throws \RuntimeException When either plaza does not exist.
     * @throws \InvalidArgumentException When $plazaArcoId is not the
     *         goalkeeper's plaza, $plazaCampoId IS the goalkeeper's plaza, or
     *         either plaza does not belong to $teamId/$seasonId.
     */
    private function assertPlazasDeReasignacionArquero( int $plazaArcoId, int $plazaCampoId, int $teamId, int $seasonId ): void {
        if ( $plazaArcoId === $plazaCampoId ) {
            throw new \InvalidArgumentException(
                "SolicitudRepository::crearReasignacionArquero(): plaza_arco_id and plaza_campo_id must be different plazas (both {$plazaArcoId})."
            );
        }

        $plazaArco = $this->plazaRepository->findPlaza( $plazaArcoId );

        if ( null === $plazaArco ) {
            throw new \RuntimeException( "SolicitudRepository::crearReasignacionArquero(): plaza_arco_id {$plazaArcoId} does not exist." );
        }

        $plazaCampo = $this->plazaRepository->findPlaza( $plazaCampoId );

        if ( null === $plazaCampo ) {
            throw new \RuntimeException( "SolicitudRepository::crearReasignacionArquero(): plaza_campo_id {$plazaCampoId} does not exist." );
        }

        if ( ! (bool) ( $plazaArco['es_arco'] ?? false ) ) {
            throw new \InvalidArgumentException(
                "SolicitudRepository::crearReasignacionArquero(): plaza_arco_id {$plazaArcoId} is not the goalkeeper's plaza (es_arco=0)."
            );
        }

        if ( (bool) ( $plazaCampo['es_arco'] ?? false ) ) {
            throw new \InvalidArgumentException(
                "SolicitudRepository::crearReasignacionArquero(): plaza_campo_id {$plazaCampoId} IS the goalkeeper's plaza — movement 2 must target a field plaza."
            );
        }

        if ( (int) $plazaArco['team_id'] !== $teamId || (int) $plazaCampo['team_id'] !== $teamId ) {
            throw new \InvalidArgumentException(
                'SolicitudRepository::crearReasignacionArquero(): both plazas must belong to team_id ' . $teamId . '.'
            );
        }

        if ( (int) $plazaArco['season_id'] !== $seasonId || (int) $plazaCampo['season_id'] !== $seasonId ) {
            throw new \InvalidArgumentException(
                'SolicitudRepository::crearReasignacionArquero(): both plazas must belong to season_id ' . $seasonId . '.'
            );
        }
    }

    /**
     * True when $fresco's verdict differs from $original's — either
     * `procede()` itself flipped, or the same verdict was reached through a
     * different set of motivo codes. See class docblock, "RE-EVALUATING
     * BEFORE APPLYING".
     */
    private function dictamenDivergio( DictamenSnapshot $original, Dictamen $fresco ): bool {
        $codigosOriginales = $original->motivoCodigos();
        $codigosFrescos     = array_map( static fn ( $m ) => $m->codigo(), $fresco->motivos() );

        sort( $codigosOriginales );
        sort( $codigosFrescos );

        return $original->procede() !== $fresco->procede() || $codigosOriginales !== $codigosFrescos;
    }

    /**
     * Common "abort the whole lote, nothing written" return shape for
     * publicarLote()'s pre-flight phase — reached before any write starts,
     * so there is nothing to roll back, only to report.
     *
     * @param int[]    $ids
     * @param int|null $culpritId The ONE solicitud id responsible for the
     *        abort, when the pre-flight phase found exactly one (every
     *        pre-flight call site has one); null for a lote-wide reason that
     *        does not point at a single id (e.g. the season_id mismatch
     *        guard) — see publicarLote()'s own docblock for the field's
     *        contract.
     * @return array{publicadas: int[], no_publicadas: int[], abortado: bool, motivo: string, divergencias: int[], culprit_id: int|null}
     */
    private function abortarLote( array $ids, string $motivo, ?int $culpritId = null ): array {
        $this->eventLog->record( 'solicitud.lote_abortado', [
            'ids'        => $ids,
            'motivo'     => $motivo,
            'culprit_id' => $culpritId,
        ] );

        return [
            'publicadas'    => [],
            'no_publicadas' => $ids,
            'abortado'      => true,
            'motivo'        => $motivo,
            'divergencias'  => [],
            'culprit_id'    => $culpritId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the query fails at the wpdb level —
     *         see Plazas\PlazaRepository's class docblock, "READ FAILURES
     *         MUST NEVER READ AS 'NO ROWS'": the same discipline applies
     *         here, even though nothing yet reads an empty list from this
     *         method as "no conflict" — a silently swallowed query failure
     *         must never look identical to a genuine empty result regardless.
     */
    private function listByEstado( int $seasonId, string $estado ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_solicitud
                  WHERE season_id = %d AND estado = %s
                  ORDER BY solicitada_at ASC, id ASC",
                $seasonId,
                $estado
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'listByEstado', [ 'season_id' => $seasonId, 'estado' => $estado ] );

        return $rows;
    }

    /**
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
                "SolicitudRepository::%s(): the query failed at the wpdb level%s.",
                $operacion,
                '' !== $lastError ? " ({$lastError})" : ''
            )
        );
    }
}
