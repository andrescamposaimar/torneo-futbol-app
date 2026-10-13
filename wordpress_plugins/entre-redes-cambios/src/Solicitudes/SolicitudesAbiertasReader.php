<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Support\ChecksReads;

/**
 * THE single place that answers "what is already spoken for by an OPEN
 * solicitud?" — an open request being `pendiente` or `aprobada`, see
 * `EstadoSolicitud::abiertas()`. Three consumers, one definition:
 *
 *   - `Dictamen\DictamenContextAssembler` (via `listAbiertasPorPlaza()` and
 *     `listAbiertasPorEntrante()`), feeding `Reglas\PlazaSinSolicitudAbierta`
 *     and `Reglas\EntranteSinSolicitudAbierta` — the GATE;
 *   - `Rest\PlazasController::listar()` (via `abiertaPorPlazaDeEquipo()`),
 *     which tells the captain's screen to hide "Pedir cambio" for a plaza
 *     that already has one;
 *   - `Plazas\CandidatosResolver::buscarPaginado()` (via
 *     `listPlayerIdsComprometidos()`), which hides a player already named in
 *     an open request from every candidate list.
 *
 * The last two are CONVENIENCES for the screen; only the first is
 * enforcement. They share this class anyway, for the reason
 * `Plazas\CandidatosResolver`'s own docblock gives for "viable": if the
 * screen and the engine each computed their own "open", the screen would
 * offer a button the engine then refuses.
 *
 * *** WHY THE PLAZA AND THE ENTRANTE ARE LOOKED UP ACROSS BOTH COLUMN PAIRS ***
 * A grouped `reasignacion_arquero` is ONE row carrying TWO plazas
 * (`plaza_id` = the goal, `plaza_campo_id` = the vacated field plaza) and TWO
 * incoming players (`entrante_player_id` = the field titular moving into
 * goal, `entrante_campo_player_id` = the outside player filling the field
 * plaza) — see `SolicitudRepository`'s class docblock, "GROUPED REQUESTS".
 * Matching only `plaza_id` / `entrante_player_id` would leave the field half
 * of that request invisible: a captain could file an ordinary substitution on
 * the field plaza the grouped request is already vacating, or name its
 * outside player again somewhere else. Every lookup below therefore matches
 * BOTH columns of the pair.
 *
 * *** A `regreso` COMMITS ITS RETURNING TITULAR (0.1.21) ***
 * A `regreso` stores a NULL `entrante_player_id` — who returns is never a
 * choice the request makes, it is the plaza's `titular_player_id` (see
 * `Dictamen\SolicitudDeCambio`'s class docblock). That NULL is a STORAGE
 * detail, not a statement about the domain: the returning titular IS an
 * incoming player, and "no se puede solicitar que ingrese un mismo jugador
 * dos veces" applies to him exactly as to a named entrante. Without this,
 * while a `regreso` of titular T to P1 was open, another captain could file
 * a `sustitucion` on P2 naming T and both were accepted (demonstrated by a
 * test before it was closed). So the two player lookups
 * (`listAbiertasPorEntrante()`, `listPlayerIdsComprometidos()`) ALSO treat
 * the titular of an open `regreso`'s plaza as committed, derived with a JOIN
 * on `cambios_plaza` inside the SAME single query — no schema change, no N+1.
 * It never blocks the `regreso` itself: `Dictamen\DictamenContextAssembler`
 * does not look an entrante up for a `regreso`, and every lookup honours
 * `$excluirSolicitudId`, which `publicarLote()` depends on.
 *
 * *** THE PLAYER LOOKUP IS SEASON-WIDE, NEVER TEAM-SCOPED ***
 * "No se puede solicitar que ingrese un mismo jugador dos veces o a dos
 * equipos distintos" (the process owner). A team filter on the entrante
 * lookups would let two captains each name the same outside player, and the
 * second request would only fail on Friday, when `publicarLote()`
 * re-evaluates the lote. The plaza lookups are season-scoped for the same
 * reason `cambios_plaza` is: a plaza id is already unique, the season filter
 * only keeps the `idx_season_estado` index in play.
 *
 * *** EVERY READ FAILS CLOSED — A FAILED READ MUST NEVER READ AS "NOTHING IS OPEN" ***
 * `$wpdb->get_results()` returns `null` both for "no rows" and for "the
 * query failed" (see `Support\ChecksReads`). An empty result from here is
 * read by the open-request rules as PERMISSION to file a second request, and
 * by the candidate filter as "this player is free" — a silently failed query
 * would grant exactly what these rules exist to forbid, and nobody would see
 * it: the captain would get a 200 and the process owner a duplicate in the
 * bandeja. Every method therefore goes through `assertReadSucceeded()`, which
 * logs `lectura.fallida` and THROWS; the callers let that propagate
 * (`DictamenPipeline` logs `dictamen.fallido` and re-throws, the controllers
 * answer a logged 500). Do not "make this more forgiving" with a `?: []`.
 *
 * Not `final` — mocked as a collaborator by `Rest\PlazasControllerTest` and
 * `Plazas\CandidatosResolverTest`, like every other repository-shaped class
 * in this plugin.
 */
class SolicitudesAbiertasReader {

    use ChecksReads;

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * Every open solicitud that targets $plazaId in EITHER of its two plaza
     * columns, excluding $excluirSolicitudId (the row being re-evaluated at
     * `publicarLote()` time — see `Dictamen\SolicitudDeCambio`'s class
     * docblock, "`solicitudId()`"; null for a request not yet persisted).
     *
     * @return array<int, array{id: int, estado: string}> Oldest first.
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    public function listAbiertasPorPlaza( int $seasonId, int $plazaId, ?int $excluirSolicitudId = null ): array {
        return $this->consultar(
            'listAbiertasPorPlaza',
            $seasonId,
            'plaza_id',
            'plaza_campo_id',
            $plazaId,
            $excluirSolicitudId,
            [ 'season_id' => $seasonId, 'plaza_id' => $plazaId ]
        );
    }

    /**
     * Every open solicitud naming $playerId as an incoming player in EITHER
     * entrante column, across EVERY team of the season — see class docblock,
     * "THE PLAYER LOOKUP IS SEASON-WIDE". Same exclusion contract as
     * `listAbiertasPorPlaza()`.
     *
     * @return array<int, array{id: int, estado: string}> Oldest first.
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    public function listAbiertasPorEntrante( int $seasonId, int $playerId, ?int $excluirSolicitudId = null ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $estados = EstadoSolicitud::abiertas();

        $sql = "SELECT s.id, s.estado
                  FROM {$p}cambios_solicitud s
                  LEFT JOIN {$p}cambios_plaza pl ON pl.id = s.plaza_id
                 WHERE s.season_id = %d AND s.estado IN (" . $this->placeholders( $estados ) . ")
                   AND ( s.entrante_player_id = %d
                         OR s.entrante_campo_player_id = %d
                         OR ( s.tipo = 'regreso' AND pl.titular_player_id = %d ) )";

        $values = array_merge( [ $seasonId ], $estados, [ $playerId, $playerId, $playerId ] );

        if ( null !== $excluirSolicitudId ) {
            $sql     .= ' AND s.id <> %d';
            $values[] = $excluirSolicitudId;
        }

        $rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY s.id ASC', $values ), ARRAY_A );

        $this->assertReadSucceeded( $rows, 'listAbiertasPorEntrante', [ 'season_id' => $seasonId, 'player_id' => $playerId ] );

        return array_map(
            static fn ( array $row ): array => [ 'id' => (int) $row['id'], 'estado' => (string) $row['estado'] ],
            $rows
        );
    }

    /**
     * Every player id named as an incoming player by ANY open solicitud of
     * the season (both entrante columns, plus the returning titular of every
     * open `regreso` — see class docblock), de-duplicated.
     *
     * ONE query for the whole season, never one per candidate: this feeds
     * `Plazas\CandidatosResolver::buscarPaginado()`, which applies it to the
     * POPULATION before `total` and before the page slice.
     *
     * @return int[]
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    public function listPlayerIdsComprometidos( int $seasonId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $estados = EstadoSolicitud::abiertas();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.entrante_player_id, s.entrante_campo_player_id,
                        CASE WHEN s.tipo = 'regreso' THEN pl.titular_player_id ELSE NULL END AS regreso_titular_player_id
                   FROM {$p}cambios_solicitud s
                   LEFT JOIN {$p}cambios_plaza pl ON pl.id = s.plaza_id
                  WHERE s.season_id = %d AND s.estado IN (" . $this->placeholders( $estados ) . ')',
                array_merge( [ $seasonId ], $estados )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'listPlayerIdsComprometidos', [ 'season_id' => $seasonId ] );

        $ids = [];

        foreach ( $rows as $row ) {
            foreach ( [ 'entrante_player_id', 'entrante_campo_player_id', 'regreso_titular_player_id' ] as $columna ) {
                if ( isset( $row[ $columna ] ) && (int) $row[ $columna ] > 0 ) {
                    $ids[ (int) $row[ $columna ] ] = true;
                }
            }
        }

        return array_keys( $ids );
    }

    /**
     * BATCHED, NEVER ONE QUERY PER ROW: the open solicitud of EVERY plaza of
     * ($seasonId, $teamId) in ONE query, keyed by plaza id — what
     * `Rest\PlazasController::listar()` shapes into each plaza's
     * `solicitud_abierta`. A plaza absent from the result has none. A
     * grouped request is indexed under BOTH its plazas (`plaza_id` and
     * `plaza_campo_id`), so the captain's screen hides the button on the
     * goal plaza AND on the field plaza it is vacating.
     *
     * If a plaza somehow carries more than one open solicitud (rows created
     * before 0.1.21 enforced this), the OLDEST wins — deterministic, and the
     * one the process owner will already have seen in the bandeja.
     *
     * @return array<int, array{id: int, estado: string}>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    public function abiertaPorPlazaDeEquipo( int $seasonId, int $teamId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $estados = EstadoSolicitud::abiertas();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, estado, plaza_id, plaza_campo_id
                   FROM {$p}cambios_solicitud
                  WHERE season_id = %d AND team_id = %d AND estado IN (" . $this->placeholders( $estados ) . ')
                  ORDER BY id ASC',
                array_merge( [ $seasonId, $teamId ], $estados )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'abiertaPorPlazaDeEquipo', [ 'season_id' => $seasonId, 'team_id' => $teamId ] );

        return $this->indexarPorPlaza( $rows );
    }

    /**
     * The same index as `abiertaPorPlazaDeEquipo()`, for the WHOLE season
     * instead of one team (0.1.22) — what `Admin\HistoricoPage` needs to
     * tell apart the two very different situations a live substitution can
     * be in once it is liberable:
     *
     *   - the captain has already filed the regreso and it is waiting for
     *     the process owner or for Friday's lote, or
     *   - nobody has asked for anything and the substitute simply stays.
     *
     * Only the second one needs chasing, and without this method the screen
     * could not distinguish them — it would nag about rows already sitting
     * in the very tray next door.
     *
     * @return array<int, array{id: int, estado: string}>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    public function abiertaPorPlazaDeTemporada( int $seasonId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $estados = EstadoSolicitud::abiertas();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, estado, plaza_id, plaza_campo_id
                   FROM {$p}cambios_solicitud
                  WHERE season_id = %d AND estado IN (" . $this->placeholders( $estados ) . ')
                  ORDER BY id ASC',
                array_merge( [ $seasonId ], $estados )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'abiertaPorPlazaDeTemporada', [ 'season_id' => $seasonId ] );

        return $this->indexarPorPlaza( $rows );
    }

    /**
     * Indexes raw `{id, estado, plaza_id, plaza_campo_id}` rows by plaza id,
     * shared by both `abiertaPorPlaza*()` readers so they can never drift on
     * the two rules that matter: a GROUPED request is indexed under BOTH its
     * plazas, and when a plaza somehow carries more than one open solicitud
     * the OLDEST wins (the rows arrive `id ASC`, and the first write to a
     * key sticks).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id: int, estado: string}>
     */
    private function indexarPorPlaza( array $rows ): array {
        $porPlaza = [];

        foreach ( $rows as $row ) {
            $abierta = [ 'id' => (int) $row['id'], 'estado' => (string) $row['estado'] ];

            foreach ( [ 'plaza_id', 'plaza_campo_id' ] as $columna ) {
                $plazaId = isset( $row[ $columna ] ) ? (int) $row[ $columna ] : 0;

                if ( $plazaId > 0 && ! isset( $porPlaza[ $plazaId ] ) ) {
                    $porPlaza[ $plazaId ] = $abierta;
                }
            }
        }

        return $porPlaza;
    }

    /**
     * @param string               $columnaA Internal column-name constant, never caller input.
     * @param string               $columnaB Internal column-name constant, never caller input.
     * @param array<string, mixed> $contexto
     * @return array<int, array{id: int, estado: string}>
     * @throws \RuntimeException
     */
    private function consultar( string $operacion, int $seasonId, string $columnaA, string $columnaB, int $valor, ?int $excluirSolicitudId, array $contexto ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $estados = EstadoSolicitud::abiertas();

        $sql    = "SELECT id, estado FROM {$p}cambios_solicitud
                    WHERE season_id = %d AND estado IN (" . $this->placeholders( $estados ) . ")
                      AND ( {$columnaA} = %d OR {$columnaB} = %d )";
        $values = array_merge( [ $seasonId ], $estados, [ $valor, $valor ] );

        if ( null !== $excluirSolicitudId ) {
            $sql     .= ' AND id <> %d';
            $values[] = $excluirSolicitudId;
        }

        $rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id ASC', $values ), ARRAY_A );

        $this->assertReadSucceeded( $rows, $operacion, $contexto );

        return array_map(
            static fn ( array $row ): array => [ 'id' => (int) $row['id'], 'estado' => (string) $row['estado'] ],
            $rows
        );
    }

    /**
     * @param string[] $values
     */
    private function placeholders( array $values ): string {
        return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
    }
}
