<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;

/**
 * Renders and handles POST for the process owner's bandeja (slug:
 * cambios-bandeja) — the screen that replaces the Excel sheet + email
 * back-and-forth described in this plugin's slice 4e task brief.
 *
 * *** THE REAL-WORLD SHAPE THIS SCREEN MIRRORS ***
 * The process owner reviews solicitudes all week (Wednesday/Thursday) and
 * approves or rejects them AS THEY ARRIVE — `handleAprobar()` /
 * `handleRechazar()` below — but nothing about a team's roster changes until
 * Friday's lote is published — `handlePublicarLote()`. See
 * `Solicitudes\SolicitudRepository`'s class docblock, "APROBAR IS NOT
 * PUBLICAR", for why `aprobar()` never touches `PlazaRepository`.
 *
 * *** SECURITY, THE SAME WAY EVERY OTHER ADMIN PAGE IN THIS CODEBASE DOES IT ***
 * Mirrors entre-redes-prode's Admin\SettingsPage / Admin\RegistryPage:
 *   - `ProcessOwnerAuthorizer::autorizado()` is checked in BOTH render() AND
 *     handlePost() (the dispatcher, so EVERY action is covered by one check,
 *     not one per action) — see that class's own docblock for why this is a
 *     capability, not `manage_options`.
 *   - Every mutating action verifies a nonce via `check_admin_referer()`
 *     BEFORE touching the repository — a forged link must never be able to
 *     publish Friday's lote in the name of whoever has an open admin
 *     session.
 *   - Every write records an EventLog event BEFORE responding — most of it
 *     already happens inside `SolicitudRepository` itself (see that class's
 *     "OBSERVABILITY" docblock); this class additionally logs its OWN
 *     authorization failures, mirroring `Rest\SolicitudesController`'s
 *     `rest.autorizacion_denegada` discipline for the REST side.
 *   - Every value this class prints is escaped — `esc_html()` / `esc_attr()`
 *     / `esc_url()` — no exception.
 *
 * *** WHY THE "CORE" METHODS ARE PRIVATE AND SEPARATE FROM THE POST HANDLERS ***
 * `handleAprobar()` / `handleRechazar()` / `handlePublicarLote()` each end in
 * `wp_safe_redirect()` + `exit` (the PRG pattern every admin page in this
 * codebase follows) — PHPUnit cannot assert anything after a real `exit`
 * executes in-process. `ejecutarAprobar()` / `ejecutarRechazar()` /
 * `ejecutarPublicarLote()` hold the actual logic with no `exit` anywhere, so
 * tests invoke THEM directly (via `ReflectionMethod::invoke()`, same pattern
 * entre-redes-prode's `RegistryPageTest` uses for `finalizeUnlink()`) and
 * assert on their return value instead of parsing a redirect.
 *
 * *** THE EXPLICIT CONFIRMATION GATE ON PUBLICAR EL LOTE ***
 * `publicarLote()` changes real rosters, all at once, the day before a fecha
 * is played — see `Solicitudes\SolicitudRepository::publicarLote()`'s own
 * docblock. `ejecutarPublicarLote()` therefore refuses to even CALL it unless
 * the request explicitly carries `confirmar_publicacion=1` — a checkbox the
 * form requires but that this class ALSO re-checks server-side, since an
 * HTML `required` attribute is a UI nicety a forged request would simply
 * omit. Missing confirmation is reported the exact same shape a real abort
 * is (`abortado: true`, `confirmado: false`), so `render()` has one code path
 * for both, never two.
 */
class BandejaPage {

    public const SLUG = 'cambios-bandeja';

    private const NONCE_APROBAR         = 'cambios_aprobar';
    private const NONCE_APROBAR_FIELD   = 'cambios_aprobar_nonce';
    private const NONCE_RECHAZAR        = 'cambios_rechazar';
    private const NONCE_RECHAZAR_FIELD  = 'cambios_rechazar_nonce';
    private const NONCE_PUBLICAR        = 'cambios_publicar_lote';
    private const NONCE_PUBLICAR_FIELD  = 'cambios_publicar_lote_nonce';

    private ProcessOwnerAuthorizer $authorizer;
    private SolicitudRepository $solicitudRepository;
    private PlazaRepository $plazaRepository;
    private Settings $settings;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /**
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch — same injectable-clock discipline as
     *        Rest\SolicitudesController's constructor, for the same reason:
     *        a test can freeze "now" instead of depending on the real clock.
     *        Defaults to the real clock.
     */
    public function __construct(
        ProcessOwnerAuthorizer $authorizer,
        SolicitudRepository $solicitudRepository,
        PlazaRepository $plazaRepository,
        Settings $settings,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer          = $authorizer;
        $this->solicitudRepository = $solicitudRepository;
        $this->plazaRepository     = $plazaRepository;
        $this->settings            = $settings;
        $this->eventLog            = $eventLog;
        $this->clockFn             = $clockFn ?? static fn (): int => time();
    }

    // -------------------------------------------------------------------------
    // POST dispatcher — registered on admin_init
    // -------------------------------------------------------------------------

    /**
     * Registered on admin_init. A SINGLE authorization check gates every
     * action this page can perform — see class docblock — so a future action
     * added here can never forget to check it.
     */
    public function handlePost(): void {
        $action = (string) ( $_POST['cambios_action'] ?? '' );

        if ( '' === $action ) {
            return;
        }

        if ( ! $this->authorizer->autorizado() ) {
            $this->eventLog->record( 'admin.autorizacion_denegada', [
                'pantalla' => 'cambios-bandeja',
                'accion'   => $action,
                'wp_user'  => get_current_user_id(),
            ] );

            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-cambios' ) );
        }

        match ( $action ) {
            'aprobar'       => $this->handleAprobar(),
            'rechazar'      => $this->handleRechazar(),
            'publicar_lote' => $this->handlePublicarLote(),
            default         => null,
        };
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! $this->authorizer->autorizado() ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-cambios' ) );
        }

        $seasonId = $this->settings->seasonId();

        $wpUserId     = get_current_user_id();
        $noticeKey    = 'cambios_bandeja_notice_' . $wpUserId;
        $notice       = get_transient( $noticeKey );
        if ( false !== $notice ) {
            delete_transient( $noticeKey );
        }

        $loteKey       = 'cambios_bandeja_lote_' . $wpUserId;
        $loteResultado = get_transient( $loteKey );
        if ( false !== $loteResultado ) {
            delete_transient( $loteKey );
        }

        $pendientes = array_map(
            fn ( array $row ): array => $this->shapeSolicitud( $row ),
            $this->solicitudRepository->listPendientes( $seasonId )
        );

        $aprobadas = array_map(
            fn ( array $row ): array => $this->shapeSolicitud( $row ),
            $this->solicitudRepository->listAprobadas( $seasonId )
        );

        $adminUrl = admin_url( 'admin.php?page=' . self::SLUG );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( is_array( $notice ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( (string) $notice['tipo'] ); ?> is-dismissible">
                <p><?php echo esc_html( (string) $notice['mensaje'] ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( is_array( $loteResultado ) ) : ?>
                <?php $culpritMensaje = $this->culpritMensaje( $loteResultado ); ?>
                <?php if ( null !== $culpritMensaje ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $culpritMensaje ); ?></p>
                </div>
                <?php else : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: number of solicitudes published */
                                __( 'Lote publicado correctamente (%d solicitudes).', 'entre-redes-cambios' ),
                                count( (array) ( $loteResultado['publicadas'] ?? [] ) )
                            )
                        );
                        ?>
                    </p>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Pendientes', 'entre-redes-cambios' ); ?></h2>
            <?php $this->renderTablaPendientes( $pendientes, $adminUrl ); ?>

            <h2><?php esc_html_e( 'Aprobadas — esperando el lote', 'entre-redes-cambios' ); ?></h2>
            <?php $this->renderTablaAprobadas( $aprobadas, $adminUrl ); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Render — internal helpers
    // -------------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $pendientes */
    private function renderTablaPendientes( array $pendientes, string $adminUrl ): void {
        if ( empty( $pendientes ) ) {
            echo '<p>' . esc_html__( 'No hay solicitudes pendientes.', 'entre-redes-cambios' ) . '</p>';
            return;
        }

        foreach ( $pendientes as $solicitud ) {
            $this->renderFilaSolicitud( $solicitud, $adminUrl, true );
        }
    }

    /** @param array<int, array<string, mixed>> $aprobadas */
    private function renderTablaAprobadas( array $aprobadas, string $adminUrl ): void {
        if ( empty( $aprobadas ) ) {
            echo '<p>' . esc_html__( 'No hay solicitudes aprobadas esperando el lote.', 'entre-redes-cambios' ) . '</p>';
            return;
        }

        ?>
        <form method="post" action="<?php echo esc_url( $adminUrl ); ?>">
            <?php wp_nonce_field( self::NONCE_PUBLICAR, self::NONCE_PUBLICAR_FIELD ); ?>
            <input type="hidden" name="cambios_action" value="publicar_lote">

            <?php foreach ( $aprobadas as $solicitud ) : ?>
                <?php $this->renderFilaSolicitud( $solicitud, $adminUrl, false ); ?>
                <label>
                    <input type="checkbox" name="solicitud_ids[]" value="<?php echo esc_attr( (string) $solicitud['id'] ); ?>">
                    <?php esc_html_e( 'Incluir en el lote', 'entre-redes-cambios' ); ?>
                </label>
            <?php endforeach; ?>

            <hr>
            <p>
                <label>
                    <input type="checkbox" name="confirmar_publicacion" value="1" required>
                    <strong>
                        <?php esc_html_e( 'Confirmo que quiero publicar el lote seleccionado. Esta acción aplica los cambios sobre los planteles reales.', 'entre-redes-cambios' ); ?>
                    </strong>
                </label>
            </p>
            <?php submit_button( __( 'Publicar el lote', 'entre-redes-cambios' ), 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $solicitud As shaped by shapeSolicitud().
     */
    private function renderFilaSolicitud( array $solicitud, string $adminUrl, bool $conAcciones ): void {
        ?>
        <div class="cambios-solicitud" style="border:1px solid #ccc;padding:10px;margin-bottom:10px;">
            <p>
                <strong>#<?php echo esc_html( (string) $solicitud['id'] ); ?></strong>
                — <?php echo esc_html( (string) $solicitud['team_nombre'] ); ?>
                — plaza <?php echo esc_html( (string) $solicitud['plaza_id'] ); ?>
                (<?php echo esc_html( (string) $solicitud['tipo'] ); ?>)
                — fecha <?php echo esc_html( (string) $solicitud['fecha_id'] ); ?>
            </p>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: who is leaving, 2: who is entering */
                        ( ! empty( $solicitud['es_grupo'] ) )
                            ? __( 'Arco — Sale: %1$s — Entra: %2$s', 'entre-redes-cambios' )
                            : __( 'Sale: %1$s — Entra: %2$s', 'entre-redes-cambios' ),
                        (string) ( $solicitud['quien_sale_nombre'] ?? '—' ),
                        (string) ( $solicitud['quien_entra_nombre'] ?? '—' )
                    )
                );
                ?>
            </p>
            <?php if ( ! empty( $solicitud['es_grupo'] ) ) : ?>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: who is leaving the field plaza, 2: who is entering it */
                        __( 'Campo — Sale: %1$s — Entra: %2$s', 'entre-redes-cambios' ),
                        (string) ( $solicitud['quien_sale_campo_nombre'] ?? '—' ),
                        (string) ( $solicitud['quien_entra_campo_nombre'] ?? '—' )
                    )
                );
                ?>
            </p>
            <?php endif; ?>
            <p>
                <?php if ( $solicitud['dictamen_procede'] ) : ?>
                    <strong style="color:green;"><?php esc_html_e( 'Dictamen: procede.', 'entre-redes-cambios' ); ?></strong>
                <?php else : ?>
                    <strong style="color:#b32d2e;"><?php esc_html_e( 'Dictamen: NO procede.', 'entre-redes-cambios' ); ?></strong>
                    <ul>
                        <?php foreach ( (array) $solicitud['dictamen_motivos'] as $motivo ) : ?>
                        <li><?php echo esc_html( (string) ( $motivo['mensaje'] ?? '' ) ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </p>

            <?php if ( ! empty( $solicitud['decisiones'] ) ) : ?>
            <p><strong><?php esc_html_e( 'Historial de decisiones', 'entre-redes-cambios' ); ?></strong></p>
            <ul>
                <?php foreach ( (array) $solicitud['decisiones'] as $decision ) : ?>
                <li>
                    <?php
                    echo esc_html(
                        sprintf(
                            '%s — %s (%s)%s',
                            (string) ( $decision['decidida_at'] ?? '' ),
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

            <?php if ( $conAcciones ) : ?>
            <form method="post" action="<?php echo esc_url( $adminUrl ); ?>" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field( self::NONCE_APROBAR, self::NONCE_APROBAR_FIELD ); ?>
                <input type="hidden" name="cambios_action" value="aprobar">
                <input type="hidden" name="solicitud_id" value="<?php echo esc_attr( (string) $solicitud['id'] ); ?>">
                <input type="text" name="nota" placeholder="<?php echo esc_attr__( 'Nota (opcional)', 'entre-redes-cambios' ); ?>">
                <?php submit_button( __( 'Aprobar', 'entre-redes-cambios' ), 'primary', 'submit', false ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( $adminUrl ); ?>" style="display:inline-block;">
                <?php wp_nonce_field( self::NONCE_RECHAZAR, self::NONCE_RECHAZAR_FIELD ); ?>
                <input type="hidden" name="cambios_action" value="rechazar">
                <input type="hidden" name="solicitud_id" value="<?php echo esc_attr( (string) $solicitud['id'] ); ?>">
                <input type="text" name="nota" placeholder="<?php echo esc_attr__( 'Nota (opcional)', 'entre-redes-cambios' ); ?>">
                <?php submit_button( __( 'Rechazar', 'entre-redes-cambios' ), 'secondary', 'submit', false ); ?>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // POST handlers (thin — nonce check, gather input, delegate, redirect)
    // -------------------------------------------------------------------------

    private function handleAprobar(): void {
        $this->verificarNonceOMorir( self::NONCE_APROBAR, self::NONCE_APROBAR_FIELD );

        $id           = absint( $_POST['solicitud_id'] ?? 0 );
        $nota         = $this->sanitizeNota( $_POST['nota'] ?? '' );
        $redirectBase = admin_url( 'admin.php?page=' . self::SLUG );

        if ( $id <= 0 ) {
            $this->guardarNotice( 'error', __( 'ID de solicitud inválido.', 'entre-redes-cambios' ) );
            wp_safe_redirect( $redirectBase );
            exit;
        }

        $resultado = $this->ejecutarAprobar( $id, $nota );

        $this->guardarNotice(
            $resultado['ok'] ? 'success' : 'error',
            $resultado['ok']
                ? sprintf( __( 'Solicitud #%d aprobada.', 'entre-redes-cambios' ), $id )
                : sprintf( __( 'No se pudo aprobar la solicitud #%1$d: %2$s', 'entre-redes-cambios' ), $id, (string) $resultado['error'] )
        );

        wp_safe_redirect( $redirectBase );
        exit;
    }

    private function handleRechazar(): void {
        $this->verificarNonceOMorir( self::NONCE_RECHAZAR, self::NONCE_RECHAZAR_FIELD );

        $id           = absint( $_POST['solicitud_id'] ?? 0 );
        $nota         = $this->sanitizeNota( $_POST['nota'] ?? '' );
        $redirectBase = admin_url( 'admin.php?page=' . self::SLUG );

        if ( $id <= 0 ) {
            $this->guardarNotice( 'error', __( 'ID de solicitud inválido.', 'entre-redes-cambios' ) );
            wp_safe_redirect( $redirectBase );
            exit;
        }

        $resultado = $this->ejecutarRechazar( $id, $nota );

        $this->guardarNotice(
            $resultado['ok'] ? 'success' : 'error',
            $resultado['ok']
                ? sprintf( __( 'Solicitud #%d rechazada.', 'entre-redes-cambios' ), $id )
                : sprintf( __( 'No se pudo rechazar la solicitud #%1$d: %2$s', 'entre-redes-cambios' ), $id, (string) $resultado['error'] )
        );

        wp_safe_redirect( $redirectBase );
        exit;
    }

    private function handlePublicarLote(): void {
        $this->verificarNonceOMorir( self::NONCE_PUBLICAR, self::NONCE_PUBLICAR_FIELD );

        $idsCrudos = (array) ( $_POST['solicitud_ids'] ?? [] );
        $ids       = array_values( array_filter( array_map( 'absint', $idsCrudos ), static fn ( int $id ): bool => $id > 0 ) );
        $confirmado = '1' === (string) ( $_POST['confirmar_publicacion'] ?? '' );

        $resultado = $this->ejecutarPublicarLote( $ids, $confirmado, get_current_user_id(), $this->nombreDecisor() );

        set_transient( 'cambios_bandeja_lote_' . get_current_user_id(), $resultado, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Core action logic — no exit, no redirect, no wp_die: directly testable.
    // -------------------------------------------------------------------------

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function ejecutarAprobar( int $id, ?string $nota ): array {
        try {
            $this->solicitudRepository->aprobar( $id, get_current_user_id(), $nota, $this->ahoraDb(), $this->nombreDecisor() );

            return [ 'ok' => true, 'error' => null ];
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'admin.solicitud_decision_fallida', [
                'operacion'    => 'aprobar',
                'solicitud_id' => $id,
                'excepcion'    => get_class( $e ),
                'mensaje'      => $e->getMessage(),
            ] );

            return [ 'ok' => false, 'error' => __( 'Ocurrió un error al procesar la solicitud.', 'entre-redes-cambios' ) ];
        }
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function ejecutarRechazar( int $id, ?string $nota ): array {
        try {
            $this->solicitudRepository->rechazar( $id, get_current_user_id(), $nota, $this->ahoraDb(), $this->nombreDecisor() );

            return [ 'ok' => true, 'error' => null ];
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'admin.solicitud_decision_fallida', [
                'operacion'    => 'rechazar',
                'solicitud_id' => $id,
                'excepcion'    => get_class( $e ),
                'mensaje'      => $e->getMessage(),
            ] );

            return [ 'ok' => false, 'error' => __( 'Ocurrió un error al procesar la solicitud.', 'entre-redes-cambios' ) ];
        }
    }

    /**
     * The Friday lote — see class docblock, "THE EXPLICIT CONFIRMATION GATE".
     * Refuses to call SolicitudRepository::publicarLote() at all unless
     * $confirmado is true; an empty $ids list is likewise reported as an
     * abort with no culprit rather than silently doing nothing.
     *
     * @param int[] $ids
     * @return array{publicadas: int[], no_publicadas: int[], abortado: bool, motivo: string|null, divergencias: int[], culprit_id: int|null, confirmado: bool}
     */
    private function ejecutarPublicarLote( array $ids, bool $confirmado, int $resueltaPor, string $decididaPorNombre ): array {
        if ( empty( $ids ) ) {
            return [
                'publicadas'    => [],
                'no_publicadas' => [],
                'abortado'      => true,
                'motivo'        => __( 'No se seleccionó ninguna solicitud para publicar.', 'entre-redes-cambios' ),
                'divergencias'  => [],
                'culprit_id'    => null,
                'confirmado'    => $confirmado,
            ];
        }

        if ( ! $confirmado ) {
            return [
                'publicadas'    => [],
                'no_publicadas' => $ids,
                'abortado'      => true,
                'motivo'        => __( 'Falta la confirmación explícita antes de publicar el lote.', 'entre-redes-cambios' ),
                'divergencias'  => [],
                'culprit_id'    => null,
                'confirmado'    => false,
            ];
        }

        $resultado = $this->solicitudRepository->publicarLote( $ids, $resueltaPor, $this->ahoraDb(), $decididaPorNombre );
        $resultado['confirmado'] = true;

        return $resultado;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * A thin, named seam around `check_admin_referer()` — exists so tests can
     * invoke JUST the nonce check (via `ReflectionMethod::invoke()`) without
     * running the rest of `handleAprobar()` / `handleRechazar()` /
     * `handlePublicarLote()`, which end in `wp_safe_redirect()` + `exit` and
     * therefore cannot be called directly from PHPUnit (see class docblock,
     * "WHY THE CORE METHODS ARE PRIVATE"). `check_admin_referer()` itself
     * calls `wp_die()` on an invalid or missing nonce — this method adds no
     * behavior of its own, it only gives that call a name tests can target.
     */
    private function verificarNonceOMorir( string $action, string $field ): void {
        check_admin_referer( $action, $field );
    }

    /**
     * The Spanish sentence render() shows when a lote result is `abortado`
     * — see class docblock referencing publicarLote()'s `culprit_id`. Pure:
     * takes the array `ejecutarPublicarLote()` / `SolicitudRepository::
     * publicarLote()` returns and never touches the repository itself, so it
     * is directly unit-testable with a canned array.
     *
     * @param array{abortado: bool, motivo: string|null, culprit_id: int|null} $resultado
     */
    private function culpritMensaje( array $resultado ): ?string {
        if ( empty( $resultado['abortado'] ) ) {
            return null;
        }

        $motivo = (string) ( $resultado['motivo'] ?? __( 'motivo desconocido', 'entre-redes-cambios' ) );

        if ( null !== ( $resultado['culprit_id'] ?? null ) ) {
            return sprintf(
                /* translators: 1: solicitud id, 2: reason */
                __( 'El lote se abortó: la solicitud #%1$d es la responsable — %2$s', 'entre-redes-cambios' ),
                (int) $resultado['culprit_id'],
                $motivo
            );
        }

        return sprintf(
            /* translators: reason */
            __( 'El lote se abortó: %s', 'entre-redes-cambios' ),
            $motivo
        );
    }

    /**
     * The acting user's display name, snapshotted NOW for
     * `cambios_decision.decidida_por_nombre` — see
     * `SolicitudRepository::insertDecisionWithinTransaction()`'s own
     * docblock for why this is captured, not looked up later.
     */
    private function nombreDecisor(): string {
        $user   = wp_get_current_user();
        $nombre = trim( (string) ( $user->display_name ?? '' ) );

        if ( '' !== $nombre ) {
            return $nombre;
        }

        $login = trim( (string) ( $user->user_login ?? '' ) );

        if ( '' !== $login ) {
            return $login;
        }

        return 'WP user #' . get_current_user_id();
    }

    private function ahoraDb(): string {
        return gmdate( 'Y-m-d H:i:s', ( $this->clockFn )() );
    }

    private function sanitizeNota( mixed $nota ): ?string {
        $nota = trim( sanitize_text_field( (string) $nota ) );

        return '' === $nota ? null : $nota;
    }

    private function guardarNotice( string $tipo, string $mensaje ): void {
        set_transient(
            'cambios_bandeja_notice_' . get_current_user_id(),
            [ 'tipo' => $tipo, 'mensaje' => $mensaje ],
            60
        );
    }

    /**
     * Shapes one `cambios_solicitud` row (as returned by
     * SolicitudRepository::listPendientes() / listAprobadas()) into
     * everything render() needs: team/plaza/quién-sale/quién-entra, the
     * dictamen already made (from `dictamen_original` — see
     * Dictamen\DictamenSnapshot), and the full decision history (see
     * SolicitudRepository::listDecisiones()).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeSolicitud( array $row ): array {
        $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );
        $plaza    = $this->plazaRepository->findPlaza( (int) $row['plaza_id'] );
        $vigente  = null !== $plaza ? $this->plazaRepository->findOcupacionVigente( (int) $plaza['id'] ) : null;

        $quienSaleId = null !== $vigente ? (int) $vigente['player_id'] : null;

        if ( SolicitudDeCambio::TIPO_SUSTITUCION === $row['tipo'] ) {
            $quienEntraId = null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null;
        } elseif ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $row['tipo'] ) {
            // Movement 1 — see class docblock, "GROUPED REQUESTS": the field
            // titular moving into goal, exactly like a `sustitucion`'s own
            // `entrante_player_id` branch above.
            $quienEntraId = null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null;
        } else {
            $quienEntraId = null !== $plaza ? (int) $plaza['titular_player_id'] : null;
        }

        // Movement 2 of a `reasignacion_arquero` — the vacated field plaza —
        // is a SECOND "Sale/Entra" pair the tray must show alongside
        // movement 1's, so the process owner judges the WHOLE move, not half
        // of it (see class docblock, "THE EXPLICIT CONFIRMATION GATE" and
        // `renderFilaSolicitud()` below). `null` for every other tipo, which
        // has no second movement.
        $esGrupo           = SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $row['tipo'];
        $quienSaleCampoId  = null;
        $quienEntraCampoId = null;

        if ( $esGrupo ) {
            $plazaCampo        = $this->plazaRepository->findPlaza( (int) $row['plaza_campo_id'] );
            $vigenteCampo      = null !== $plazaCampo ? $this->plazaRepository->findOcupacionVigente( (int) $plazaCampo['id'] ) : null;
            $quienSaleCampoId  = null !== $vigenteCampo ? (int) $vigenteCampo['player_id'] : null;
            $quienEntraCampoId = null !== $row['entrante_campo_player_id'] ? (int) $row['entrante_campo_player_id'] : null;
        }

        return [
            'id'                       => (int) $row['id'],
            'team_id'                  => (int) $row['team_id'],
            'team_nombre'              => $this->nombrePost( (int) $row['team_id'], __( 'Equipo', 'entre-redes-cambios' ) ),
            'plaza_id'                 => (int) $row['plaza_id'],
            'tipo'                     => (string) $row['tipo'],
            'fecha_id'                 => (int) $row['fecha_id'],
            'quien_sale_id'            => $quienSaleId,
            'quien_sale_nombre'        => null !== $quienSaleId ? $this->nombrePost( $quienSaleId, __( 'Jugador', 'entre-redes-cambios' ) ) : null,
            'quien_entra_id'           => $quienEntraId,
            'quien_entra_nombre'       => null !== $quienEntraId ? $this->nombrePost( $quienEntraId, __( 'Jugador', 'entre-redes-cambios' ) ) : null,
            'es_grupo'                 => $esGrupo,
            'quien_sale_campo_id'      => $quienSaleCampoId,
            'quien_sale_campo_nombre'  => null !== $quienSaleCampoId ? $this->nombrePost( $quienSaleCampoId, __( 'Jugador', 'entre-redes-cambios' ) ) : null,
            'quien_entra_campo_id'     => $quienEntraCampoId,
            'quien_entra_campo_nombre' => null !== $quienEntraCampoId ? $this->nombrePost( $quienEntraCampoId, __( 'Jugador', 'entre-redes-cambios' ) ) : null,
            'estado'                   => (string) $row['estado'],
            'solicitada_at'            => (string) $row['solicitada_at'],
            'dictamen_procede'         => $snapshot->procede(),
            'dictamen_motivos'         => $snapshot->motivos(),
            'decisiones'               => $this->solicitudRepository->listDecisiones( (int) $row['id'] ),
        ];
    }

    private function nombrePost( int $postId, string $fallbackLabel ): string {
        $titulo = trim( (string) get_the_title( $postId ) );

        return '' !== $titulo ? $titulo : $fallbackLabel . ' #' . $postId;
    }
}
