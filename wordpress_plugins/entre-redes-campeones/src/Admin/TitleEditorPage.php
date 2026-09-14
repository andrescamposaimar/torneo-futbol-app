<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\NameNormalizer;
use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use EntreRedes\Campeones\Titles\WriteFailedException;

/**
 * Renders and handles POST for the hidden "one title" editor (slug:
 * campeones-titulo-edit, registered via add_submenu_page(null, ...) —
 * design §6). With no titulo_id in the request it is the "create a new
 * title" form (ADMIN-7); with one, it is the header edit plus full squad
 * row CRUD and the per-row link control.
 *
 * The link control here is deliberately minimal: an operator-typed
 * WordPress player id, not a name search. Ranked, accent-insensitive
 * search (PlayerSearch / the autocomplete endpoint) is slice 5's job —
 * building it here would duplicate work and pre-empt that design.
 *
 * A squad row add always runs LinkResolver (LINK-1) over it. A row edit
 * re-runs it too, on EVERY successful edit of a row that is not already
 * `manual` — not only when the stored name changes, but also on a
 * captain-flag-only or order-only touch-up (see handleEditRow()'s own
 * docblock for why). A `manual` row is never silently re-evaluated by any
 * of this — a human decision stands until a human changes it.
 *
 * Security, identical to TitlesPage / entre-redes-prode's RegistryPage:
 * manage_options re-checked in both render() and handlePost(); PRG after
 * every POST; WP_List_Table required behind a class_exists guard at render
 * time. Nonce, one per action family: crear_titulo uses a fixed action
 * name (there is no id yet); actualizar_titulo and agregar_fila are
 * title-scoped (campeones_actualizar_titulo_{tituloId} /
 * campeones_agregar_fila_{tituloId}), both carried in the
 * campeones_editor_nonce field; editar_fila / eliminar_fila / vincular /
 * cambiar / desvincular share one per-row nonce, campeones_link_{plantelId},
 * carried in campeones_link_nonce.
 */
class TitleEditorPage {

    private const ACTIONS = [
        'crear_titulo',
        'actualizar_titulo',
        'agregar_fila',
        'editar_fila',
        'eliminar_fila',
        'vincular',
        'cambiar',
        'desvincular',
    ];

    public function __construct(
        private readonly TitleRepository $titles,
        private readonly SquadRepository $squads,
        private readonly LinkResolver $resolver,
        private readonly LinkWriteService $linkWriter
    ) {
    }

    // -------------------------------------------------------------------------
    // POST handler — registered on admin_init
    // -------------------------------------------------------------------------

    public function handlePost(): void {
        $action = (string) ( $_POST['campeones_editor_action'] ?? '' );
        if ( '' === $action || ! in_array( $action, self::ACTIONS, true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-campeones' ) );
        }

        $tituloId = absint( $_POST['titulo_id'] ?? 0 );

        $this->verifyNonceOrDie( $action, $tituloId );

        $redirectTituloId = $tituloId;
        $notice           = 'error';

        try {
            // Pure dispatch from here: each case parses/validates its own
            // request fields and calls its own private handler in one
            // dispatchXxx() method (item 11) — handlePost() itself no
            // longer carries inline sanitisation/validation for eight
            // different actions.
            switch ( $action ) {
                case 'crear_titulo':
                    [ $notice, $newTituloId ] = $this->dispatchCrearTitulo();
                    if ( null !== $newTituloId ) {
                        $redirectTituloId = $newTituloId;
                    }
                    break;

                case 'actualizar_titulo':
                    $notice = $this->dispatchActualizarTitulo( $tituloId );
                    break;

                case 'agregar_fila':
                    $notice = $this->dispatchAgregarFila( $tituloId );
                    break;

                case 'editar_fila':
                    $notice = $this->dispatchEditarFila( $tituloId );
                    break;

                case 'eliminar_fila':
                    $notice = $this->dispatchEliminarFila( $tituloId );
                    break;

                case 'vincular':
                case 'cambiar':
                    $notice = $this->dispatchVincularOCambiar( $tituloId );
                    break;

                case 'desvincular':
                    $notice = $this->dispatchDesvincular( $tituloId );
                    break;
            }
        } catch ( PlayerDirectoryQueryException $e ) {
            // Neither exception is caught anywhere else in this class. In
            // handleAddRow() specifically, the throw can happen AFTER the
            // row's own insert already committed — a fatal here would abort
            // the redirect mid-write and hand the operator WordPress's
            // critical-error screen instead of a notice they can act on.
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: %s failed for titulo_id=%d (plantel_id=%d) querying the player directory. %s',
                $action,
                $tituloId,
                absint( $_POST['plantel_id'] ?? 0 ),
                $e->getMessage()
            ) );
            $notice = 'error_directorio';
        } catch ( WriteFailedException $e ) {
            // Item 4: a local DB write failure has nothing to do with the
            // player directory. Reporting it with error_directorio's copy
            // ("Error al consultar el directorio de jugadores... esperá
            // unos minutos") tells the operator to wait out an external
            // dependency while their own database is what actually broke.
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: %s failed for titulo_id=%d (plantel_id=%d) writing to the database. %s',
                $action,
                $tituloId,
                absint( $_POST['plantel_id'] ?? 0 ),
                $e->getMessage()
            ) );
            $notice = 'error_guardado';
        }

        $redirectUrl = admin_url( 'admin.php?page=campeones-titulo-edit&titulo_id=' . $redirectTituloId );
        wp_safe_redirect( add_query_arg( 'campeones_notice', $notice, $redirectUrl ) );
        $this->terminateAfterRedirect();
    }

    /**
     * Isolated in its own method (rather than a bare `exit;` inline in
     * handlePost()) so a test can override this single point with a
     * catchable signal instead of ending the PHP process outright — the
     * only way to drive a real success path through the public handlePost()
     * entry point instead of Reflection (see
     * tests/Support/TestableTitleEditorPage.php).
     */
    protected function terminateAfterRedirect(): void {
        exit;
    }

    /**
     * Verifies the correct nonce for $action before handlePost() dispatches
     * to it, mirroring TitlesPage::handlePost():49-55. crear_titulo /
     * actualizar_titulo / agregar_fila are title-scoped (there is no
     * plantel_id yet, or the action is not row-scoped); the other four are
     * scoped to the one row they act on, sharing the same per-row nonce the
     * row's forms already generate (SquadListTable::column_acciones()).
     */
    private function verifyNonceOrDie( string $action, int $tituloId ): void {
        $titleScoped = [
            'crear_titulo'      => 'campeones_crear_titulo',
            'actualizar_titulo' => 'campeones_actualizar_titulo_' . $tituloId,
            'agregar_fila'      => 'campeones_agregar_fila_' . $tituloId,
        ];

        if ( isset( $titleScoped[ $action ] ) ) {
            $nonce = (string) ( $_POST['campeones_editor_nonce'] ?? '' );
            if ( ! wp_verify_nonce( $nonce, $titleScoped[ $action ] ) ) {
                wp_die( esc_html__( 'Verificación de seguridad fallida. Por favor recargá la página e intentá de nuevo.', 'entre-redes-campeones' ) );
            }
            return;
        }

        // editar_fila / eliminar_fila / vincular / cambiar / desvincular —
        // one nonce per row, shared across the row's action forms.
        $plantelId = absint( $_POST['plantel_id'] ?? 0 );
        $nonce     = (string) ( $_POST['campeones_link_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'campeones_link_' . $plantelId ) ) {
            wp_die( esc_html__( 'Verificación de seguridad fallida. Por favor recargá la página e intentá de nuevo.', 'entre-redes-campeones' ) );
        }
    }

    /**
     * Guards every row-scoped action (editar_fila, eliminar_fila, vincular,
     * cambiar, desvincular) against a row that does not belong to the
     * requested title. The per-row nonce (campeones_link_{plantelId}) is
     * scoped to the ROW, not the title — a request pairing a genuinely
     * valid row nonce with a different titulo_id (a stale tab, a
     * bookmarked URL, a hand-built POST) would otherwise silently edit,
     * relink, or delete a row belonging to a different year (item 1). This
     * is an authorization check, not a CSRF one, and it must run before any
     * handler that mutates the row.
     */
    private function rowBelongsToRequestedTitle( int $rowId, int $tituloId ): bool {
        $entry = $this->squads->find( $rowId );
        return null !== $entry && $entry->tituloId === $tituloId;
    }

    // -------------------------------------------------------------------------
    // Per-action dispatch (item 11) — each method parses/validates its own
    // $_POST fields and calls the matching private mutation handler,
    // keeping handlePost()'s switch a plain one-line-per-case dispatch.
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: ?int} [$notice, $newTituloId]
     */
    private function dispatchCrearTitulo(): array {
        $newId = $this->handleCreateTitle(
            absint( $_POST['anio'] ?? 0 ),
            sanitize_text_field( (string) ( $_POST['zona'] ?? 'A' ) ),
            sanitize_text_field( (string) ( $_POST['posicion'] ?? 'campeon' ) ),
            sanitize_text_field( (string) ( $_POST['equipo_nombre'] ?? '' ) )
        );

        return null !== $newId ? [ 'creado', $newId ] : [ 'conflicto', null ];
    }

    private function dispatchActualizarTitulo( int $tituloId ): string {
        return $this->handleUpdateHeader( $tituloId, sanitize_text_field( (string) ( $_POST['equipo_nombre'] ?? '' ) ) )
            ? 'actualizado'
            : 'error_actualizar';
    }

    private function dispatchAgregarFila( int $tituloId ): string {
        $jugadorNombre = sanitize_text_field( (string) ( $_POST['jugador_nombre'] ?? '' ) );
        if ( '' === $jugadorNombre || '' === NameNormalizer::normalize( $jugadorNombre ) ) {
            // Item 8: the add-row form only guards this with the HTML5
            // `required` attribute — a client-side-only check a hand-built
            // POST ignores entirely.
            return 'error_nombre_requerido';
        }

        $result = $this->handleAddRow( $tituloId, $jugadorNombre, ! empty( $_POST['es_capitan'] ) );

        return match ( true ) {
            ! $result->rowSaved => 'error_fila',
            $result->directoryUnavailable => 'fila_agregada_sin_vinculo_directorio',
            ! $result->linkResolved => 'fila_agregada_sin_vinculo',
            default => 'fila_agregada',
        };
    }

    private function dispatchEditarFila( int $tituloId ): string {
        $plantelId = absint( $_POST['plantel_id'] ?? 0 );
        if ( ! $this->rowBelongsToRequestedTitle( $plantelId, $tituloId ) ) {
            return 'error_fila_ajena';
        }

        $jugadorNombre = sanitize_text_field( (string) ( $_POST['jugador_nombre'] ?? '' ) );
        if ( '' === $jugadorNombre || '' === NameNormalizer::normalize( $jugadorNombre ) ) {
            return 'error_nombre_requerido';
        }

        $result = $this->handleEditRow(
            $plantelId,
            $jugadorNombre,
            ! empty( $_POST['es_capitan'] ),
            absint( $_POST['orden'] ?? 0 )
        );

        return match ( true ) {
            ! $result->rowSaved => 'error_fila',
            ! $result->linkResolved => 'fila_actualizada_sin_vinculo',
            default => 'fila_actualizada',
        };
    }

    private function dispatchEliminarFila( int $tituloId ): string {
        $plantelId = absint( $_POST['plantel_id'] ?? 0 );
        if ( ! $this->rowBelongsToRequestedTitle( $plantelId, $tituloId ) ) {
            return 'error_fila_ajena';
        }

        return $this->handleDeleteRow( $plantelId ) ? 'fila_eliminada' : 'error_fila';
    }

    private function dispatchVincularOCambiar( int $tituloId ): string {
        $plantelId = absint( $_POST['plantel_id'] ?? 0 );
        if ( ! $this->rowBelongsToRequestedTitle( $plantelId, $tituloId ) ) {
            return 'error_fila_ajena';
        }

        // A missing/zero jugador_id must be rejected here, before it ever
        // reaches handleSetLink() — absint(...) ?: null would otherwise turn
        // it into null, which is exactly the desvincular write. Two buttons
        // presented as opposites must never collapse into the same write
        // (item 8).
        $jugadorId = absint( $_POST['jugador_id'] ?? 0 );
        if ( 0 === $jugadorId ) {
            return 'error_id_requerido';
        }

        return $this->handleSetLink( $plantelId, $jugadorId ) ? 'vinculado' : 'error_vincular';
    }

    private function dispatchDesvincular( int $tituloId ): string {
        $plantelId = absint( $_POST['plantel_id'] ?? 0 );
        if ( ! $this->rowBelongsToRequestedTitle( $plantelId, $tituloId ) ) {
            return 'error_fila_ajena';
        }

        return $this->handleSetLink( $plantelId, null ) ? 'desvinculado' : 'error_vincular';
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-campeones' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $tituloId = absint( $_GET['titulo_id'] ?? 0 );
        $title    = 0 !== $tituloId ? $this->titles->find( $tituloId ) : null;
        $notice   = $this->resolveNotice();

        if ( null === $title ) {
            $this->renderCreateForm( $notice );
            return;
        }

        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $rows      = $this->squads->findByTitle( $tituloId );
        $tableRows = array_map(
            static fn ( SquadEntry $e ): array => [
                'id'              => $e->id,
                'orden'           => $e->orden,
                'jugador_nombre'  => $e->jugadorNombre,
                'es_capitan'      => $e->esCapitan,
                'estado_vinculo'  => $e->estadoVinculo,
                'jugador_id'      => $e->jugadorId,
            ],
            $rows
        );

        $listTable = new SquadListTable( [ 'singular' => 'jugador', 'plural' => 'jugadores', 'ajax' => false ] );
        $listTable->setData( $tableRows, $tituloId );
        $listTable->prepare_items();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( sprintf( '%s %s — %s', esc_html( $title->posicion ), (string) $title->anio, esc_html( $title->equipoNombre ) ) ); ?></h1>

            <?php if ( null !== $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=campeones-titulo-edit' ) ); ?>">
                <input type="hidden" name="campeones_editor_action" value="actualizar_titulo">
                <input type="hidden" name="titulo_id" value="<?php echo esc_attr( (string) $tituloId ); ?>">
                <?php wp_nonce_field( 'campeones_actualizar_titulo_' . $tituloId, 'campeones_editor_nonce' ); ?>
                <label>
                    <?php echo esc_html__( 'Equipo', 'entre-redes-campeones' ); ?>
                    <input type="text" name="equipo_nombre" value="<?php echo esc_attr( $title->equipoNombre ); ?>">
                </label>
                <?php submit_button( __( 'Guardar', 'entre-redes-campeones' ) ); ?>
            </form>

            <?php $listTable->display(); ?>

            <h2><?php echo esc_html__( 'Agregar jugador', 'entre-redes-campeones' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=campeones-titulo-edit' ) ); ?>">
                <input type="hidden" name="campeones_editor_action" value="agregar_fila">
                <input type="hidden" name="titulo_id" value="<?php echo esc_attr( (string) $tituloId ); ?>">
                <?php wp_nonce_field( 'campeones_agregar_fila_' . $tituloId, 'campeones_editor_nonce' ); ?>
                <label>
                    <?php echo esc_html__( 'Jugador (Apellido, Nombre)', 'entre-redes-campeones' ); ?>
                    <input type="text" name="jugador_nombre" required>
                </label>
                <label>
                    <input type="checkbox" name="es_capitan" value="1">
                    <?php echo esc_html__( 'Capitán', 'entre-redes-campeones' ); ?>
                </label>
                <?php submit_button( __( 'Agregar', 'entre-redes-campeones' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array{message: string, type: string}|null $notice
     */
    private function renderCreateForm( ?array $notice = null ): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Nuevo título', 'entre-redes-campeones' ); ?></h1>
            <?php if ( null !== $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=campeones-titulo-edit' ) ); ?>">
                <input type="hidden" name="campeones_editor_action" value="crear_titulo">
                <?php wp_nonce_field( 'campeones_crear_titulo', 'campeones_editor_nonce' ); ?>
                <label><?php echo esc_html__( 'Año', 'entre-redes-campeones' ); ?> <input type="number" name="anio"></label>
                <label><?php echo esc_html__( 'Zona', 'entre-redes-campeones' ); ?> <input type="text" name="zona" value="A"></label>
                <label><?php echo esc_html__( 'Posición', 'entre-redes-campeones' ); ?> <input type="text" name="posicion" value="campeon"></label>
                <label><?php echo esc_html__( 'Equipo', 'entre-redes-campeones' ); ?> <input type="text" name="equipo_nombre"></label>
                <?php submit_button( __( 'Crear', 'entre-redes-campeones' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Resolves $_GET['campeones_notice'] (set by handlePost()'s PRG
     * redirect) into a displayable message + notice type, mirroring
     * TitlesPage::resolveNotice() / entre-redes-prode's
     * RegistryPage::render():80-99. Without this, every failed create /
     * update / row add / row edit / row delete / link change looked exactly
     * like a successful one.
     *
     * @return array{message: string, type: string}|null
     */
    private function resolveNotice(): ?array {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! isset( $_GET['campeones_notice'] ) ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $key = sanitize_text_field( (string) $_GET['campeones_notice'] );

        return match ( $key ) {
            'creado'           => [ 'message' => __( 'El título fue creado correctamente.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'conflicto'        => [ 'message' => __( 'Ya existe un título para ese año, zona y posición.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'actualizado'      => [ 'message' => __( 'Los datos del título fueron actualizados.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'error_actualizar' => [ 'message' => __( 'Error al actualizar el título. Intentá nuevamente.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'fila_agregada'    => [ 'message' => __( 'El jugador fue agregado al plantel.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'fila_agregada_sin_vinculo' => [ 'message' => __( 'El jugador fue agregado al plantel, pero no se pudo evaluar su vínculo. Usá "Revalidar" o vinculalo manualmente.', 'entre-redes-campeones' ), 'type' => 'warning' ],
            'fila_agregada_sin_vinculo_directorio' => [ 'message' => __( 'El jugador fue agregado al plantel, pero no se pudo evaluar su vínculo porque el directorio de jugadores no está disponible en este momento. Vinculalo manualmente o reintentá más tarde.', 'entre-redes-campeones' ), 'type' => 'warning' ],
            'fila_actualizada' => [ 'message' => __( 'La fila fue actualizada.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'fila_actualizada_sin_vinculo' => [ 'message' => __( 'La fila fue actualizada, pero no se pudo re-evaluar su vínculo. Usá "Revalidar" o vinculalo manualmente.', 'entre-redes-campeones' ), 'type' => 'warning' ],
            'fila_eliminada'   => [ 'message' => __( 'La fila fue eliminada del plantel.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'error_fila'       => [ 'message' => __( 'Error al guardar la fila. Intentá nuevamente.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'error_nombre_requerido' => [ 'message' => __( 'Ingresá un nombre de jugador válido.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'vinculado'        => [ 'message' => __( 'El jugador fue vinculado.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'desvinculado'     => [ 'message' => __( 'El vínculo fue quitado.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'error_vincular'   => [ 'message' => __( 'Error al modificar el vínculo. Intentá nuevamente.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'error_id_requerido' => [ 'message' => __( 'Ingresá un ID de jugador para vincular.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'error_fila_ajena' => [ 'message' => __( 'Esa fila no pertenece a este título.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'error_directorio' => [ 'message' => __( 'Error al consultar el directorio de jugadores. Intentá nuevamente en unos minutos.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'error_guardado'   => [ 'message' => __( 'Error al guardar los datos. Intentá nuevamente en unos minutos.', 'entre-redes-campeones' ), 'type' => 'error' ],
            default            => null,
        };
    }

    // -------------------------------------------------------------------------
    // Private mutation handlers — no capability check of their own; the
    // caller (handlePost()) is the single gate. Kept free of redirect/exit
    // so they can be exercised directly in tests.
    // -------------------------------------------------------------------------

    private function handleCreateTitle( int $anio, string $zona, string $posicion, string $equipoNombre ): ?int {
        $created = $this->titles->createOrConflict( $anio, $zona, $posicion, $equipoNombre );
        return null === $created ? null : $created->id;
    }

    private function handleUpdateHeader( int $tituloId, string $equipoNombre ): bool {
        return $this->titles->update( $tituloId, [ 'equipo_nombre' => $equipoNombre ] );
    }

    /**
     * Adds a squad row and immediately runs LinkResolver over it (LINK-1) —
     * a manually-added row is resolved exactly like an imported one.
     *
     * Returns a RowSaveResult whose rowSaved/linkResolved are independent
     * (item 3): rowSaved is true as soon as the insert lands, regardless of
     * what happens next. If applyResolution()'s write then fails, the row
     * stays in the plain `sin_candidato` state insert() gave it — visible
     * and re-revalidatable later — and linkResolved is false so the caller
     * can tell "the row exists but its link needs attention" apart from
     * "nothing was saved at all". Telling an operator to retry when the row
     * already exists would create a duplicate (item 3).
     *
     * The resolve()/applyResolution() sequence runs AFTER the insert above
     * has already committed, so a PlayerDirectoryQueryException thrown by
     * resolve() (BLOCKER round-2 fix) is caught HERE, not left to propagate
     * to handlePost()'s outer catch. That outer catch has no way to tell
     * "nothing happened" apart from "the row is already durably saved" — it
     * would report the same 'error_directorio' copy ("Intentá nuevamente")
     * that a genuine no-op failure gets, and an operator following that
     * advice on an add-row form resubmits and creates a duplicate row. The
     * row stays in its plain `sin_candidato` state (visible and
     * re-revalidatable later) and the caller is told the row was saved but
     * its link could not be evaluated.
     */
    private function handleAddRow( int $tituloId, string $jugadorNombre, bool $esCapitan ): RowSaveResult {
        $title = $this->titles->find( $tituloId );
        if ( null === $title ) {
            return RowSaveResult::notSaved();
        }

        $orden = count( $this->squads->findByTitle( $tituloId ) );

        $id = $this->squads->insert(
            new SquadEntry( $tituloId, $orden, $jugadorNombre, $esCapitan, 'sin_candidato', null, NameNormalizer::normalize( $jugadorNombre ) )
        );

        try {
            $resolution   = $this->resolver->resolve( $jugadorNombre, $title->anio );
            $linkResolved = $this->linkWriter->applyResolution( $id, $resolution );
        } catch ( PlayerDirectoryQueryException $e ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: agregar_fila saved plantel_id=%d (titulo_id=%d) but could not evaluate its link — the player directory is unavailable. %s',
                $id,
                $tituloId,
                $e->getMessage()
            ) );
            return RowSaveResult::savedDirectoryUnavailable( $id );
        }

        return RowSaveResult::saved( $id, $linkResolved );
    }

    /**
     * Edits a squad row's name/captain flag/order. LinkResolver (LINK-1) is
     * re-run on every successful update to a row that is not currently
     * `manual` — not only when the name actually changed, but also on a
     * captain-flag-only or order-only edit. This is broader than earlier
     * revisions of this docblock claimed, and is a deliberate choice, not
     * an oversight left uncorrected: re-resolving is idempotent for an
     * unchanged name (the same LinkResolver input yields the same
     * LinkResolution) and keeps this method's behaviour simple to reason
     * about — one rule ("not manual -> always re-resolved on edit"),
     * rather than a second one ("...unless only the flag/order changed")
     * that would need its own justification and its own tests. A `manual`
     * row is still never touched, regardless of what changed
     * (see test_handle_edit_row_never_re_resolves_a_manual_row and
     * test_handle_edit_row_re_resolves_even_when_only_the_captain_flag_changes).
     *
     * Returns a RowSaveResult whose rowSaved/linkResolved are independent
     * (item 3), for the same reason as handleAddRow(): if the field update
     * succeeds but the follow-up re-resolution then fails, the edit DID
     * land — reporting the same generic failure a fully-failed edit gets
     * would tell the operator to retry an edit that already happened.
     */
    private function handleEditRow( int $rowId, string $jugadorNombre, bool $esCapitan, int $orden ): RowSaveResult {
        $entry = $this->squads->find( $rowId );
        if ( null === $entry ) {
            return RowSaveResult::notSaved();
        }

        $fieldsSaved = $this->squads->update(
            $rowId,
            [
                'jugador_nombre'      => $jugadorNombre,
                'jugador_nombre_norm' => NameNormalizer::normalize( $jugadorNombre ),
                'es_capitan'          => $esCapitan ? 1 : 0,
                'orden'               => $orden,
            ]
        );

        if ( ! $fieldsSaved ) {
            return RowSaveResult::notSaved();
        }

        if ( LinkState::MANUAL === $entry->estadoVinculo ) {
            return RowSaveResult::saved( $rowId, true );
        }

        $title = $this->titles->find( $entry->tituloId );
        if ( null === $title ) {
            return RowSaveResult::saved( $rowId, true );
        }

        $resolution   = $this->resolver->resolve( $jugadorNombre, $title->anio );
        $linkResolved = $this->linkWriter->applyResolution( $rowId, $resolution );

        return RowSaveResult::saved( $rowId, $linkResolved );
    }

    private function handleDeleteRow( int $rowId ): bool {
        return $this->squads->delete( $rowId );
    }

    /**
     * Vincular / Cambiar / Desvincular — all three are the same write
     * (LINK-8): any human set, change, or clear becomes `manual`, including
     * a clear that leaves the pointer null.
     */
    private function handleSetLink( int $rowId, ?int $jugadorId ): bool {
        return $this->linkWriter->setManualLink( $rowId, $jugadorId );
    }
}
