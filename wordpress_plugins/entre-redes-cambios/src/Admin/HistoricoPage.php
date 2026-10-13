<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

use EntreRedes\Cambios\Calendario\BoundedFechaCounter;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CadenaResolver;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;

/**
 * The process owner's read-only companion to `BandejaPage` (slug:
 * cambios-historico): the two questions the tray structurally cannot answer,
 * because the tray only ever shows `pendiente` and `aprobada` rows.
 *
 * *** QUESTION 1 — "WHICH SUBSTITUTIONS ARE LIVE RIGHT NOW?" ***
 * A published cambio does not expire. Nothing in this plugin is time-driven:
 * an ocupación closes only when another substitution succeeds it, when the
 * captain files a `regreso` that is then approved AND published, or when the
 * plaza itself is closed (`Plazas\PlazaRepository::succeedOcupacion()` /
 * `::closeOcupacionByRegresoTitular()` / `::closePlaza()`). So a substitute
 * whose captain never asks for the titular back simply stays for the rest of
 * the season, and before 0.1.22 there was no screen anywhere that would say
 * so — the captain's own roster shows it one team at a time, and the process
 * owner does not use the app.
 *
 * The "Vigentes" table therefore states, per live substitution, the ONE fact
 * that decides whether it needs chasing. `renderSituacion()` below holds the
 * four Spanish strings; in order of precedence they mean:
 *   - there is an OPEN SOLICITUD on this plaza — already filed and sitting in
 *     the tray next door, so this screen must NOT nag about it. Usually a
 *     `regreso`, but deliberately ANY open solicitud: a pending `sustitucion`
 *     or `reasignacion_arquero` speaks for the plaza just as much, which is
 *     why `Solicitudes\SolicitudesAbiertasReader::abiertaPorPlazaDeTemporada()`
 *     does not filter by tipo and why the label says "Solicitud", not
 *     "Regreso".
 *   - the minimum (see `Plazas\CadenaResolver`) is NOT met yet — how many
 *     fechas are still missing. Nothing to do.
 *   - the minimum IS met and no open solicitud touches the plaza. This is the
 *     only row that needs a human, and the only one printed in a warning
 *     colour.
 *   - the count could NOT be trusted. An honest "we do not know", never a
 *     fabricated zero; the same degradation
 *     `Rest\PlazasController::resolveFechasFaltantes()` makes for the
 *     captain, and for the same reason.
 *
 * *** QUESTION 2 — "WHAT WAS DECIDED, AND BY WHOM?" ***
 * The moment a solicitud is approved, rejected, published or annulled it
 * leaves `listPendientes()`/`listAprobadas()` and vanishes from the tray. The
 * audit trail was never lost — `cambios_decision` is append-only and records
 * who decided what, when, with which note — but nothing READ it back. The
 * "Histórico" table is that window: every terminal row of the season, newest
 * first, with its full decision chain.
 *
 * *** WHY THIS CLASS SHAPES ITS ROWS ITSELF INSTEAD OF REUSING BandejaPage ***
 * Not duplication — a different question. `BandejaPage::shapeSolicitud()`
 * derives "quién sale" from the plaza's CURRENT vigent ocupación, which is
 * correct only while the row is still open. For a solicitud published three
 * weeks ago, today's occupant is somebody else entirely, and reusing that
 * shaping would make old rows silently relabel themselves with whoever holds
 * the plaza now — stating something false about the past. This class reads
 * the frozen `saliente_player_id` snapshot instead, exactly as
 * `Rest\SolicitudesController::listar()` does for the captain, and for the
 * reason `Migrations\InitialSchema` spells out in that column's docblock.
 *
 * *** ONE BAD ROW MUST NOT BLANK THE SCREEN ***
 * The rest of this plugin throws on an unknown `tipo` rather than let it fall
 * into the wrong branch — see `Rest\SolicitudesController::listar()`, where
 * the cost of guessing is a WRONG ANSWER about a request somebody will act
 * on. Here the cost is inverted: this is a read-only audit page, and a single
 * corrupt row taking the whole render down would hide every OTHER row,
 * including the actionable "nobody asked for the titular back" ones. Same
 * shape as the lote that aborts entirely because of one row.
 * So an unrecognised `tipo` DEGRADES to a labelled "tipo desconocido" row
 * with no derived names, the two sections are built independently (one
 * failing still renders the other), and anything that does go wrong is
 * recorded ONCE PER RENDER — never once per row, because
 * `Observability\WpEventLog` keeps a single `wp_options` slot and a per-row
 * event would overwrite a more serious failure N times on one page load.
 *
 * *** SECURITY ***
 * Same gate as every other admin page here: `ProcessOwnerAuthorizer::
 * autorizado()` (the `gestionar_cambios` capability, never `manage_options`)
 * checked in `render()`, and every printed value escaped. There is no
 * `handlePost()` and no nonce because this page WRITES NOTHING — the filters
 * are GET, read through `sanitizeEstadoFiltro()`/`sanitizeEquipoFiltro()`,
 * which accept only values this class itself enumerates. A read-only screen
 * with no state-changing action has no CSRF surface to protect.
 */
class HistoricoPage {

    public const SLUG = 'cambios-historico';

    /** The GET parameters the filter form round-trips. */
    private const PARAM_ESTADO = 'estado';
    private const PARAM_EQUIPO = 'equipo';

    private ProcessOwnerAuthorizer $authorizer;
    private SolicitudRepository $solicitudRepository;
    private PlazaRepository $plazaRepository;
    private SolicitudesAbiertasReader $solicitudesAbiertas;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private EventLog $eventLog;

    public function __construct(
        ProcessOwnerAuthorizer $authorizer,
        SolicitudRepository $solicitudRepository,
        PlazaRepository $plazaRepository,
        SolicitudesAbiertasReader $solicitudesAbiertas,
        FechaRepository $fechaRepository,
        Settings $settings,
        EventLog $eventLog
    ) {
        $this->authorizer          = $authorizer;
        $this->solicitudRepository = $solicitudRepository;
        $this->plazaRepository     = $plazaRepository;
        $this->solicitudesAbiertas = $solicitudesAbiertas;
        $this->fechaRepository     = $fechaRepository;
        $this->settings            = $settings;
        $this->eventLog            = $eventLog;
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! $this->authorizer->autorizado() ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-cambios' ) );
        }

        $seasonId = $this->settings->seasonId();

        // Built INDEPENDENTLY, so a failure in one section still renders the
        // other — see class docblock, "ONE BAD ROW MUST NOT BLANK THE SCREEN".
        // The "Vigentes" table is the actionable one; losing it because the
        // histórico could not be read would be the worst possible trade.
        [ $vigentes, $falloVigentes ]   = $this->construirSeccion( 'vigentes', fn (): array => $this->construirVigentes( $seasonId ) );
        [ $resueltas, $falloHistorico ] = $this->construirSeccion( 'historico', fn (): array => $this->construirResueltas( $seasonId ) );

        $equipos = $this->equiposPresentes( $vigentes, $resueltas );

        $estadoFiltro = $this->sanitizeEstadoFiltro();
        $equipoFiltro = $this->sanitizeEquipoFiltro( $equipos );

        $resueltasFiltradas = $this->filtrar( $resueltas, $estadoFiltro, $equipoFiltro );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <h2><?php esc_html_e( 'Cambios vigentes', 'entre-redes-cambios' ); ?></h2>
            <p class="description">
                <?php
                esc_html_e(
                    'Jugadores que hoy ocupan una plaza que no es la suya. Un cambio publicado no se '
                    . 'termina solo: sigue vigente hasta que el capitán pida el regreso del titular y ese '
                    . 'regreso se apruebe y se publique.',
                    'entre-redes-cambios'
                );
                ?>
            </p>
            <?php if ( $falloVigentes ) : ?>
                <?php $this->renderFalloDeSeccion(); ?>
            <?php else : ?>
                <?php $this->renderTablaVigentes( $vigentes ); ?>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Histórico', 'entre-redes-cambios' ); ?></h2>
            <p class="description">
                <?php
                esc_html_e(
                    'Todas las solicitudes ya resueltas de la temporada — publicadas, rechazadas y anuladas — '
                    . 'de la más reciente a la más vieja, con quién decidió cada una.',
                    'entre-redes-cambios'
                );
                ?>
            </p>
            <?php if ( $falloHistorico ) : ?>
                <?php $this->renderFalloDeSeccion(); ?>
            <?php else : ?>
                <?php $this->renderFiltros( $estadoFiltro, $equipoFiltro, $equipos ); ?>
                <?php $this->renderTablaResueltas( $resueltasFiltradas, count( $resueltas ) ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Runs one section's data assembly, turning ANY failure into "this
     * section could not be loaded" instead of a WordPress critical-error
     * screen that would take the other section with it.
     *
     * The event name ends in `fallida` ON PURPOSE: `Observability\WpEventLog`
     * persists only events whose name contains `fallid`, and this host has no
     * PHP log, so without that substring a broken page would leave NO trace
     * anywhere — the operator would see a red box and nobody could ever say
     * why. It fires at most twice per render (once per section), so it cannot
     * churn the single `wp_options` slot the way a per-row event would.
     *
     * Repository reads have ALREADY recorded their own `lectura.fallida`
     * before throwing; this adds which section died on top of that.
     *
     * @param callable(): array<int, array<string, mixed>> $construir
     * @return array{0: array<int, array<string, mixed>>, 1: bool} [filas, falló]
     */
    private function construirSeccion( string $seccion, callable $construir ): array {
        try {
            return [ $construir(), false ];
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'admin.historico_seccion_fallida', [
                'seccion'   => $seccion,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return [ [], true ];
        }
    }

    private function renderFalloDeSeccion(): void {
        printf(
            '<div class="notice notice-error inline"><p>%s</p></div>',
            esc_html__(
                'No se pudo cargar esta sección. El error quedó registrado — avisale a quien mantiene el plugin.',
                'entre-redes-cambios'
            )
        );
    }

    // -------------------------------------------------------------------------
    // Vigentes
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function construirVigentes( int $seasonId ): array {
        $ocupaciones = $this->plazaRepository->listOcupacionesVigentesNoTitulares( $seasonId );

        if ( empty( $ocupaciones ) ) {
            return [];
        }

        $plazaIds = array_map( static fn ( array $o ): int => (int) $o['plaza_id'], $ocupaciones );

        // Two batched reads for the whole table, never one per row — the same
        // discipline BandejaPage::anotarMetricas() follows.
        $cadenas  = $this->plazaRepository->listOcupacionesDePlazas( $plazaIds );
        $abiertas = $this->solicitudesAbiertas->abiertaPorPlazaDeTemporada( $seasonId );

        $cadenaResolver = new CadenaResolver(
            ( new BoundedFechaCounter( $this->fechaRepository, $this->eventLog ) )
                ->boundedCountResolvedFechasSinceFn( $seasonId ),
            $this->settings->minimoFechasCambio()
        );

        $filas          = [];
        $noCalculables  = [];

        foreach ( $ocupaciones as $ocupacion ) {
            $plazaId = (int) $ocupacion['plaza_id'];
            $cadena  = $cadenas[ $plazaId ] ?? [];

            [ $faltantes, $indeterminado ] = $this->resolveFaltantes( $cadena, $cadenaResolver );

            if ( $indeterminado ) {
                $noCalculables[] = $plazaId;
            }

            $filas[] = [
                'plaza_id'        => $plazaId,
                'team_id'         => (int) $ocupacion['team_id'],
                'team_nombre'     => $this->nombrePost( (int) $ocupacion['team_id'], __( 'Equipo', 'entre-redes-cambios' ) ),
                'es_arco'         => ! empty( $ocupacion['es_arco'] ),
                'ocupante_id'     => (int) $ocupacion['player_id'],
                'ocupante_nombre' => $this->nombrePost( (int) $ocupacion['player_id'], __( 'Jugador', 'entre-redes-cambios' ) ),
                'titular_id'      => (int) $ocupacion['titular_player_id'],
                'titular_nombre'  => $this->nombrePost( (int) $ocupacion['titular_player_id'], __( 'Jugador', 'entre-redes-cambios' ) ),
                'desde_fecha_id'  => (int) $ocupacion['fecha_desde_id'],
                'faltantes'       => $faltantes,
                'indeterminado'   => $indeterminado,
                // ANY open solicitud, not just a regreso — see class
                // docblock. `abiertaPorPlazaDeTemporada()` does not filter by
                // tipo, and a pending sustitución speaks for the plaza just
                // as much as a regreso does.
                'solicitud_abierta' => $abiertas[ $plazaId ] ?? null,
            ];
        }

        // ONCE per render, never once per row — see class docblock. The name
        // deliberately does NOT contain `fallid`: this event must not take
        // over the single persisted `wp_options` slot, because the real
        // record of a broken counter belongs to `BoundedFechaCounter`, and
        // this one would overwrite a `lectura.fallida` N times on one load.
        if ( ! empty( $noCalculables ) ) {
            $this->eventLog->record( 'admin.historico_conteo_no_confiable', [
                'plazas' => $noCalculables,
                'total'  => count( $noCalculables ),
            ] );
        }

        return $filas;
    }

    /**
     * Degrades a single unreliable count to "indeterminado" instead of
     * failing the page — see `Rest\PlazasController::resolveFechasFaltantes()`,
     * which makes the identical trade for the captain's screen. The caller
     * logs the aggregate once; this method only decides.
     *
     * *** WHY AN EMPTY CHAIN DEGRADES HERE RATHER THAN THROWING ***
     * `CadenaResolver::countFechasUntilLiberacion()` throws
     * `\InvalidArgumentException` on an empty chain, documented as a CALLER
     * bug — and for its usual callers it is. Not for this one. Every plaza
     * id this page passes came out of an ocupación row read moments earlier,
     * so the only way the chain comes back empty is a RACE: the row was
     * deleted between `listOcupacionesVigentesNoTitulares()` and
     * `listOcupacionesDePlazas()`. Letting that propagate would take a
     * read-only page down over a row that no longer exists, and the
     * exception message would blame the wrong thing ("this screen assembled
     * its own data wrong"). "We could not work it out" is both true and
     * survivable.
     *
     * @param array<int, array<string, mixed>> $cadena
     * @return array{0: int|null, 1: bool} [faltantes, indeterminado]
     */
    private function resolveFaltantes( array $cadena, CadenaResolver $cadenaResolver ): array {
        if ( empty( $cadena ) ) {
            return [ null, true ];
        }

        try {
            return [ $cadenaResolver->countFechasUntilLiberacion( $cadena ), false ];
        } catch ( FechaCountUnavailableException $e ) {
            return [ null, true ];
        }
    }

    /** @param array<int, array<string, mixed>> $vigentes */
    private function renderTablaVigentes( array $vigentes ): void {
        if ( empty( $vigentes ) ) {
            echo '<p>' . esc_html__( 'No hay cambios vigentes: cada plaza la ocupa su titular.', 'entre-redes-cambios' ) . '</p>';

            return;
        }

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Equipo', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Plaza', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Ocupa', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'En lugar de', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Desde', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Situación', 'entre-redes-cambios' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $vigentes as $fila ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $fila['team_nombre'] ); ?></td>
                    <td>
                        #<?php echo esc_html( (string) $fila['plaza_id'] ); ?>
                        <?php if ( $fila['es_arco'] ) : ?>
                            <span title="<?php esc_attr_e( 'Plaza de arquero', 'entre-redes-cambios' ); ?>">🧤</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( (string) $fila['ocupante_nombre'] ); ?></td>
                    <td><?php echo esc_html( (string) $fila['titular_nombre'] ); ?></td>
                    <td><?php echo esc_html( sprintf( __( 'fecha %d', 'entre-redes-cambios' ), (int) $fila['desde_fecha_id'] ) ); ?></td>
                    <td><?php $this->renderSituacion( $fila ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The one cell this table exists for. Order matters: an already-filed
     * solicitud is reported as such EVEN IF the minimum is not met yet,
     * because "there is a request on this plaza" is the more actionable fact
     * and the tray is where it is resolved.
     *
     * @param array<string, mixed> $fila
     */
    private function renderSituacion( array $fila ): void {
        $abierta = $fila['solicitud_abierta'];

        if ( is_array( $abierta ) ) {
            printf(
                '<span style="color:#2271b1;">%s</span>',
                esc_html(
                    sprintf(
                        /* translators: 1: solicitud id, 2: estado */
                        __( 'Solicitud #%1$d abierta (%2$s)', 'entre-redes-cambios' ),
                        (int) $abierta['id'],
                        // Through etiquetaEstado(), never the raw column: the
                        // operator reads "pendiente"/"aprobada" as words, and
                        // this is the one place on this page where a
                        // NON-terminal estado is printed — which is why that
                        // method is total over every estado, not just the
                        // terminal ones.
                        $this->etiquetaEstado( (string) $abierta['estado'] )
                    )
                )
            );

            return;
        }

        if ( true === $fila['indeterminado'] ) {
            printf(
                '<span style="color:#b32d2e;">%s</span>',
                esc_html__( 'No se pudo calcular cuánto falta.', 'entre-redes-cambios' )
            );

            return;
        }

        $faltantes = (int) $fila['faltantes'];

        if ( $faltantes > 0 ) {
            echo esc_html(
                sprintf(
                    /* translators: %d: fechas still missing */
                    _n( 'Falta %d fecha para poder volver.', 'Faltan %d fechas para poder volver.', $faltantes, 'entre-redes-cambios' ),
                    $faltantes
                )
            );

            return;
        }

        printf(
            '<strong style="color:#996800;">%s</strong>',
            esc_html__( 'El titular ya puede volver — nadie pidió el regreso.', 'entre-redes-cambios' )
        );
    }

    // -------------------------------------------------------------------------
    // Histórico
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function construirResueltas( int $seasonId ): array {
        $rows = $this->solicitudRepository->listResueltas( $seasonId );

        if ( empty( $rows ) ) {
            return [];
        }

        $plazas = $this->plazaRepository->listPlazasPorIds(
            array_map( static fn ( array $r ): int => (int) $r['plaza_id'], $rows )
        );

        $filas         = [];
        $tiposInvalidos = [];

        foreach ( $rows as $row ) {
            $tipo = (string) $row['tipo'];

            // Frozen at write time, never re-derived — see class docblock,
            // "WHY THIS CLASS SHAPES ITS ROWS ITSELF".
            $saleId = null !== $row['saliente_player_id'] ? (int) $row['saliente_player_id'] : null;

            $tipoLabel = $this->etiquetaTipo( $tipo );

            // Explicit per-tipo branch, never a two-way ternary that would
            // swallow an unknown tipo into the `regreso` arm — the exact
            // mistake `Rest\SolicitudesController::listar()` documents. The
            // difference here is the final arm: an unrecognised tipo DEGRADES
            // (no derived "entra", a labelled row) instead of throwing, so
            // one corrupt row cannot hide every other row on an audit page.
            if ( SolicitudDeCambio::TIPO_SUSTITUCION === $tipo ) {
                $entraId = null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null;
            } elseif ( SolicitudDeCambio::TIPO_REGRESO === $tipo ) {
                $plaza   = $plazas[ (int) $row['plaza_id'] ] ?? null;
                $entraId = null !== $plaza ? (int) $plaza['titular_player_id'] : null;
            } elseif ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
                $entraId = null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null;
            } else {
                $entraId          = null;
                $tiposInvalidos[] = [ 'solicitud_id' => (int) $row['id'], 'tipo' => $tipo ];
            }

            $filas[] = [
                'id'                 => (int) $row['id'],
                'team_id'            => (int) $row['team_id'],
                'team_nombre'        => $this->nombrePost( (int) $row['team_id'], __( 'Equipo', 'entre-redes-cambios' ) ),
                'plaza_id'           => (int) $row['plaza_id'],
                'tipo'               => $tipo,
                'tipo_label'         => $tipoLabel ?? __( 'Tipo desconocido', 'entre-redes-cambios' ),
                'fecha_id'           => (int) $row['fecha_id'],
                'estado'             => (string) $row['estado'],
                'estado_label'       => $this->etiquetaEstado( (string) $row['estado'] ),
                'sale_nombre'        => null !== $saleId ? $this->nombrePost( $saleId, __( 'Jugador', 'entre-redes-cambios' ) ) : null,
                'entra_nombre'       => null !== $entraId ? $this->nombrePost( $entraId, __( 'Jugador', 'entre-redes-cambios' ) ) : null,
                'solicitada_at'      => $this->aHoraDelOperador( (string) $row['solicitada_at'] ),
                'resuelta_at'        => $this->aHoraDelOperador( (string) ( $row['resuelta_at'] ?? '' ) ),
                'decisiones'         => $this->solicitudRepository->listDecisiones( (int) $row['id'] ),
            ];
        }

        // ONCE per render, and the name ends in `fallida` so it IS persisted:
        // a `tipo` outside the ENUM means the data is corrupt, which is worth
        // the single `wp_options` slot. See class docblock for why it is not
        // recorded per row.
        if ( ! empty( $tiposInvalidos ) ) {
            $this->eventLog->record( 'admin.historico_filas_invalidas_fallida', [
                'filas'  => $tiposInvalidos,
                'total'  => count( $tiposInvalidos ),
            ] );
        }

        return $filas;
    }

    /**
     * @param array<int, array<string, mixed>> $filas
     */
    private function renderTablaResueltas( array $filas, int $totalSinFiltrar ): void {
        if ( 0 === $totalSinFiltrar ) {
            echo '<p>' . esc_html__( 'Todavía no se resolvió ninguna solicitud en esta temporada.', 'entre-redes-cambios' ) . '</p>';

            return;
        }

        if ( empty( $filas ) ) {
            echo '<p>' . esc_html__( 'Ninguna solicitud resuelta coincide con el filtro.', 'entre-redes-cambios' ) . '</p>';

            return;
        }

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( '#', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Equipo', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Tipo', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Movimiento', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Decisiones', 'entre-redes-cambios' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $filas as $fila ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $fila['id'] ); ?></td>
                    <td><?php echo esc_html( (string) $fila['team_nombre'] ); ?></td>
                    <td>
                        <?php echo esc_html( (string) $fila['tipo_label'] ); ?><br>
                        <small>
                            <?php echo esc_html( sprintf( __( 'plaza #%1$d · fecha %2$d', 'entre-redes-cambios' ), (int) $fila['plaza_id'], (int) $fila['fecha_id'] ) ); ?>
                        </small>
                    </td>
                    <td>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: who leaves, 2: who enters */
                                __( 'Sale: %1$s — Entra: %2$s', 'entre-redes-cambios' ),
                                (string) ( $fila['sale_nombre'] ?? __( 'sin registrar', 'entre-redes-cambios' ) ),
                                (string) ( $fila['entra_nombre'] ?? __( 'sin registrar', 'entre-redes-cambios' ) )
                            )
                        );
                        ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html( (string) $fila['estado_label'] ); ?></strong><br>
                        <small><?php echo esc_html( (string) $fila['resuelta_at'] ); ?></small>
                    </td>
                    <td>
                        <?php if ( empty( $fila['decisiones'] ) ) : ?>
                            <em><?php esc_html_e( 'Sin decisiones registradas.', 'entre-redes-cambios' ); ?></em>
                        <?php else : ?>
                        <ul style="margin:0;">
                            <?php foreach ( (array) $fila['decisiones'] as $decision ) : ?>
                            <li>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        '%s — %s (%s)%s',
                                        $this->aHoraDelOperador( (string) ( $decision['decidida_at'] ?? '' ) ),
                                        (string) ( $decision['accion'] ?? '' ),
                                        (string) ( $decision['decidida_por_nombre'] ?? '' ),
                                        ! empty( $decision['nota'] ) ? ' — ' . (string) $decision['nota'] : ''
                                    )
                                );
                                ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Filters — GET only, and only over values this class enumerates
    // -------------------------------------------------------------------------

    /**
     * @param array<int, string> $equipos team id => nombre
     */
    private function renderFiltros( string $estadoFiltro, int $equipoFiltro, array $equipos ): void {
        ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:10px 0;">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">

            <label for="cambios-historico-estado"><?php esc_html_e( 'Estado', 'entre-redes-cambios' ); ?></label>
            <select name="<?php echo esc_attr( self::PARAM_ESTADO ); ?>" id="cambios-historico-estado">
                <option value=""><?php esc_html_e( 'Todos', 'entre-redes-cambios' ); ?></option>
                <?php foreach ( EstadoSolicitud::terminales() as $estado ) : ?>
                <option value="<?php echo esc_attr( $estado ); ?>" <?php selected( $estadoFiltro, $estado ); ?>>
                    <?php echo esc_html( $this->etiquetaEstado( $estado ) ); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label for="cambios-historico-equipo"><?php esc_html_e( 'Equipo', 'entre-redes-cambios' ); ?></label>
            <select name="<?php echo esc_attr( self::PARAM_EQUIPO ); ?>" id="cambios-historico-equipo">
                <option value="0"><?php esc_html_e( 'Todos', 'entre-redes-cambios' ); ?></option>
                <?php foreach ( $equipos as $teamId => $nombre ) : ?>
                <option value="<?php echo esc_attr( (string) $teamId ); ?>" <?php selected( $equipoFiltro, $teamId ); ?>>
                    <?php echo esc_html( $nombre ); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'entre-redes-cambios' ); ?></button>
        </form>
        <?php
    }

    /**
     * Only a value `EstadoSolicitud` itself recognises as terminal survives —
     * anything else (including an OPEN estado, which has no business on this
     * screen) becomes "no filter". Never interpolated into SQL either way:
     * filtering happens in PHP over rows already fetched.
     */
    private function sanitizeEstadoFiltro(): string {
        $crudo = $_GET[ self::PARAM_ESTADO ] ?? '';

        // `?estado[]=x` sends an ARRAY. Casting that to string would raise a
        // PHP notice before the whitelist below ever rejected it — harmless
        // in outcome, noisy in a log nobody can read on this host.
        if ( ! is_scalar( $crudo ) ) {
            return '';
        }

        $raw = sanitize_text_field( wp_unslash( (string) $crudo ) );

        return in_array( $raw, EstadoSolicitud::terminales(), true ) ? $raw : '';
    }

    /**
     * @param array<int, string> $equipos
     * @return int 0 means "no filter" — including for a team id that is not
     *         actually present on this page, so a hand-edited URL can never
     *         render an empty table that looks like "this team never had a
     *         cambio".
     */
    private function sanitizeEquipoFiltro( array $equipos ): int {
        $crudo = $_GET[ self::PARAM_EQUIPO ] ?? 0;

        if ( ! is_scalar( $crudo ) ) {
            return 0;
        }

        $raw = (int) $crudo;

        return isset( $equipos[ $raw ] ) ? $raw : 0;
    }

    /**
     * @param array<int, array<string, mixed>> $filas
     * @return array<int, array<string, mixed>>
     */
    private function filtrar( array $filas, string $estado, int $equipo ): array {
        return array_values(
            array_filter(
                $filas,
                static function ( array $fila ) use ( $estado, $equipo ): bool {
                    if ( '' !== $estado && $estado !== $fila['estado'] ) {
                        return false;
                    }

                    return 0 === $equipo || $equipo === $fila['team_id'];
                }
            )
        );
    }

    /**
     * Every team named anywhere on this page, BOTH tables, sorted by name —
     * built from the rows themselves rather than from a taxonomy query, so
     * the dropdown can only ever offer a filter that has something to show.
     *
     * @param array<int, array<string, mixed>> $vigentes
     * @param array<int, array<string, mixed>> $resueltas
     * @return array<int, string> team id => nombre
     */
    private function equiposPresentes( array $vigentes, array $resueltas ): array {
        $equipos = [];

        foreach ( array_merge( $vigentes, $resueltas ) as $fila ) {
            $equipos[ (int) $fila['team_id'] ] = (string) $fila['team_nombre'];
        }

        asort( $equipos );

        return $equipos;
    }

    // -------------------------------------------------------------------------
    // Labels and formatting
    // -------------------------------------------------------------------------

    /**
     * Spanish label for ANY `cambios_solicitud.estado`, so the operator never
     * reads a raw enum. Total over all five on purpose: the histórico only
     * ever shows terminal ones, but `renderSituacion()` prints the estado of
     * an OPEN solicitud in the Vigentes table.
     *
     * Unlike its `BandejaPage` counterparts this does NOT throw on an
     * unrecognised value — see class docblock, "ONE BAD ROW MUST NOT BLANK
     * THE SCREEN". It is called during rendering, after the per-section
     * try/catch has already returned, so a throw here would take the page
     * down with no way to degrade. Saying "(desconocido)" is both honest and
     * survivable.
     */
    private function etiquetaEstado( string $estado ): string {
        return match ( $estado ) {
            EstadoSolicitud::PUBLICADA => __( 'Publicada', 'entre-redes-cambios' ),
            EstadoSolicitud::RECHAZADA => __( 'Rechazada', 'entre-redes-cambios' ),
            EstadoSolicitud::ANULADA   => __( 'Anulada', 'entre-redes-cambios' ),
            EstadoSolicitud::PENDIENTE => __( 'Pendiente', 'entre-redes-cambios' ),
            EstadoSolicitud::APROBADA  => __( 'Aprobada', 'entre-redes-cambios' ),
            default                    => __( 'Estado desconocido', 'entre-redes-cambios' ),
        };
    }

    /**
     * The same words `BandejaPage::etiquetaTipo()` and the Flutter app use,
     * or `null` when the tipo is not one this class was taught about.
     *
     * `BandejaPage::etiquetaTipo()` THROWS in that case and is right to: the
     * tray is where somebody decides, and a request whose nature is not
     * understood must not be approved. Here nobody decides anything, so the
     * caller labels the row and moves on — losing one row's label is better
     * than losing the page. See class docblock.
     */
    private function etiquetaTipo( string $tipo ): ?string {
        return match ( $tipo ) {
            SolicitudDeCambio::TIPO_SUSTITUCION          => __( 'Cambio', 'entre-redes-cambios' ),
            SolicitudDeCambio::TIPO_REGRESO              => __( 'Regreso', 'entre-redes-cambios' ),
            SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO => __( 'Reasignación de arquero', 'entre-redes-cambios' ),
            default                                      => null,
        };
    }

    /**
     * Same UTC -> operator-timezone conversion `BandejaPage` applies, for the
     * same reason: every DATETIME in this plugin is stored UTC, and an
     * operator reading "14:48" must see the wall clock they lived, not UTC.
     * Falls back to the raw value (and to UTC, labelled) rather than throwing
     * — a malformed timestamp must not take a read-only page down.
     */
    private function aHoraDelOperador( string $utcDb ): string {
        if ( '' === $utcDb ) {
            return '';
        }

        try {
            $instante = new \DateTimeImmutable( $utcDb, new \DateTimeZone( 'UTC' ) );
        } catch ( \Exception $e ) {
            return $utcDb;
        }

        try {
            $zona   = new \DateTimeZone( $this->settings->timezone() );
            $sufijo = '';
        } catch ( \Exception $e ) {
            $zona   = new \DateTimeZone( 'UTC' );
            $sufijo = ' UTC';
        }

        return $instante->setTimezone( $zona )->format( 'Y-m-d H:i:s' ) . $sufijo;
    }

    private function nombrePost( int $postId, string $fallbackLabel ): string {
        $titulo = trim( (string) get_the_title( $postId ) );

        return '' !== $titulo ? $titulo : $fallbackLabel . ' #' . $postId;
    }
}
