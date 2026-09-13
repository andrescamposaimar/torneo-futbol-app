<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\NameNormalizer;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;

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
 * Any squad row add/edit that changes the stored name re-runs LinkResolver
 * (LINK-1) unless the row is already `manual` — a human decision is never
 * silently re-evaluated by a routine header/name touch-up.
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

        switch ( $action ) {
            case 'crear_titulo':
                $newId = $this->handleCreateTitle(
                    absint( $_POST['anio'] ?? 0 ),
                    sanitize_text_field( (string) ( $_POST['zona'] ?? 'A' ) ),
                    sanitize_text_field( (string) ( $_POST['posicion'] ?? 'campeon' ) ),
                    sanitize_text_field( (string) ( $_POST['equipo_nombre'] ?? '' ) )
                );
                if ( null !== $newId ) {
                    $redirectTituloId = $newId;
                    $notice           = 'creado';
                } else {
                    $notice = 'conflicto';
                }
                break;

            case 'actualizar_titulo':
                $notice = $this->handleUpdateHeader( $tituloId, sanitize_text_field( (string) ( $_POST['equipo_nombre'] ?? '' ) ) )
                    ? 'actualizado'
                    : 'error_actualizar';
                break;

            case 'agregar_fila':
                $newRowId = $this->handleAddRow(
                    $tituloId,
                    sanitize_text_field( (string) ( $_POST['jugador_nombre'] ?? '' ) ),
                    ! empty( $_POST['es_capitan'] )
                );
                $notice = null !== $newRowId ? 'fila_agregada' : 'error_fila';
                break;

            case 'editar_fila':
                $notice = $this->handleEditRow(
                    absint( $_POST['plantel_id'] ?? 0 ),
                    sanitize_text_field( (string) ( $_POST['jugador_nombre'] ?? '' ) ),
                    ! empty( $_POST['es_capitan'] ),
                    absint( $_POST['orden'] ?? 0 )
                ) ? 'fila_actualizada' : 'error_fila';
                break;

            case 'eliminar_fila':
                $notice = $this->handleDeleteRow( absint( $_POST['plantel_id'] ?? 0 ) )
                    ? 'fila_eliminada'
                    : 'error_fila';
                break;

            case 'vincular':
            case 'cambiar':
                $notice = $this->handleSetLink( absint( $_POST['plantel_id'] ?? 0 ), absint( $_POST['jugador_id'] ?? 0 ) ?: null )
                    ? 'vinculado'
                    : 'error_vincular';
                break;

            case 'desvincular':
                $notice = $this->handleSetLink( absint( $_POST['plantel_id'] ?? 0 ), null )
                    ? 'desvinculado'
                    : 'error_vincular';
                break;
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
            'fila_actualizada' => [ 'message' => __( 'La fila fue actualizada.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'fila_eliminada'   => [ 'message' => __( 'La fila fue eliminada del plantel.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'error_fila'       => [ 'message' => __( 'Error al guardar la fila. Intentá nuevamente.', 'entre-redes-campeones' ), 'type' => 'error' ],
            'vinculado'        => [ 'message' => __( 'El jugador fue vinculado.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'desvinculado'     => [ 'message' => __( 'El vínculo fue quitado.', 'entre-redes-campeones' ), 'type' => 'success' ],
            'error_vincular'   => [ 'message' => __( 'Error al modificar el vínculo. Intentá nuevamente.', 'entre-redes-campeones' ), 'type' => 'error' ],
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
     * Returns null if applyResolution()'s write fails, even though the row
     * itself was already inserted — the row is left in the plain
     * `sin_candidato` state insert() gave it (visible and re-revalidatable
     * later), but the caller is never told this add fully succeeded when
     * the resolution it promised silently did not happen (item 6).
     */
    private function handleAddRow( int $tituloId, string $jugadorNombre, bool $esCapitan ): ?int {
        $title = $this->titles->find( $tituloId );
        if ( null === $title ) {
            return null;
        }

        $orden = count( $this->squads->findByTitle( $tituloId ) );

        $id = $this->squads->insert(
            new SquadEntry( $tituloId, $orden, $jugadorNombre, $esCapitan, 'sin_candidato', null, NameNormalizer::normalize( $jugadorNombre ) )
        );

        $resolution = $this->resolver->resolve( $jugadorNombre, $title->anio );
        if ( ! $this->linkWriter->applyResolution( $id, $resolution ) ) {
            return null;
        }

        return $id;
    }

    /**
     * Edits a squad row's name/captain flag/order. A name change re-runs
     * LinkResolver (LINK-1) UNLESS the row is currently `manual` — a human
     * decision is never silently re-evaluated by an unrelated edit.
     */
    private function handleEditRow( int $rowId, string $jugadorNombre, bool $esCapitan, int $orden ): bool {
        $entry = $this->squads->find( $rowId );
        if ( null === $entry ) {
            return false;
        }

        $ok = $this->squads->update(
            $rowId,
            [
                'jugador_nombre'      => $jugadorNombre,
                'jugador_nombre_norm' => NameNormalizer::normalize( $jugadorNombre ),
                'es_capitan'          => $esCapitan ? 1 : 0,
                'orden'               => $orden,
            ]
        );

        if ( $ok && LinkState::MANUAL !== $entry->estadoVinculo ) {
            $title = $this->titles->find( $entry->tituloId );
            if ( null !== $title ) {
                $resolution = $this->resolver->resolve( $jugadorNombre, $title->anio );
                // Fold the resolution write's own result into $ok (item 6) —
                // the row's name/captain/orden fields did save, but a failed
                // re-resolution must not be reported as a successful edit.
                $ok = $this->linkWriter->applyResolution( $rowId, $resolution ) && $ok;
            }
        }

        return $ok;
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
