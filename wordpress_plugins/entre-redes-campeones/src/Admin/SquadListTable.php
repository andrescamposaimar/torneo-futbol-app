<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

use EntreRedes\Campeones\Linking\LinkState;

/**
 * WP_List_Table subclass for one title's squad, on the hidden
 * campeones-titulo-edit subpage (design §6).
 *
 * Columns: orden, jugador (name + captain marker), estado, vinculado_a
 * (the linked player's real name), jugador_id (the linked player's raw id,
 * shown plainly so an operator can copy it into another row's "ID jugador"
 * field), acciones (Editar / Eliminar the row, plus Vincular / Cambiar /
 * Desvincular the link).
 *
 * The link control is a raw player-id field (task 3.4's own scope note —
 * ranked name search is slice 5), submitted alongside the per-row nonce
 * campeones_link_{plantelId} (design §6, LINK-8).
 */
class SquadListTable extends \WP_List_Table {

    /** @var array<int, array<string, mixed>> */
    private array $itemsData = [];

    private int $tituloId = 0;

    /**
     * @var array<int, string> Linked sp_player id => display name, supplied
     *                          by a batched lookup (TitleEditorPage::render()
     *                          via PlayerDirectoryInterface::findByIds()) —
     *                          never resolved one row at a time.
     */
    private array $playerNamesById = [];

    /**
     * Whether the batched player-name lookup itself failed (the directory
     * was unavailable). Distinct from a single dangling pointer: a
     * directory-wide outage must never be reported as "every row is
     * unlinked" — that confusion is the exact defect class this plugin has
     * already had to fix (see TitleEditorPage's directory-unavailable
     * notices).
     */
    private bool $directoryUnavailable = false;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setData( array $items, int $tituloId ): void {
        $this->itemsData = $items;
        $this->tituloId  = $tituloId;
        $this->items       = $items;
    }

    /**
     * @param array<int, string> $namesById
     */
    public function setPlayerLookup( array $namesById, bool $directoryUnavailable = false ): void {
        $this->playerNamesById      = $namesById;
        $this->directoryUnavailable = $directoryUnavailable;
    }

    /** @return array<string, string> */
    public function get_columns(): array {
        return [
            'orden'          => __( 'Orden', 'entre-redes-campeones' ),
            'jugador_nombre' => __( 'Jugador', 'entre-redes-campeones' ),
            'estado_vinculo' => __( 'Estado', 'entre-redes-campeones' ),
            'vinculado_a'    => __( 'Vinculado a', 'entre-redes-campeones' ),
            'jugador_id'     => __( 'ID', 'entre-redes-campeones' ),
            'acciones'       => __( 'Acciones', 'entre-redes-campeones' ),
        ];
    }

    public function prepare_items(): void {
        $this->_column_headers = [ $this->get_columns(), [], [] ];

        $this->set_pagination_args( [
            'total_items' => count( $this->itemsData ),
            'per_page'    => count( $this->itemsData ) ?: 1,
            'total_pages' => 1,
        ] );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_default( $item, $column_name ): string {
        return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_jugador_nombre( $item ): string {
        $name = esc_html( (string) $item['jugador_nombre'] );
        if ( ! empty( $item['es_capitan'] ) ) {
            $name .= ' ' . esc_html__( '(C)', 'entre-redes-campeones' );
        }
        return $name;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_estado_vinculo( $item ): string {
        $labels = [
            LinkState::AUTO          => __( 'Automático', 'entre-redes-campeones' ),
            LinkState::AMBIGUO       => __( 'Ambiguo', 'entre-redes-campeones' ),
            LinkState::SIN_CANDIDATO => __( 'Sin candidato', 'entre-redes-campeones' ),
            LinkState::MANUAL        => __( 'Manual', 'entre-redes-campeones' ),
        ];

        $estado = (string) ( $item['estado_vinculo'] ?? '' );
        return esc_html( $labels[ $estado ] ?? $estado );
    }

    /**
     * The linked player's real name (post_title), or an unambiguous
     * marker — never a blank cell that could read as a rendering bug.
     *
     * A jugador_id set but absent from the batched lookup is a dangling
     * pointer (the player was deleted or unpublished) and must say so
     * loudly, distinct from "the directory could not be queried at all".
     * A silently empty name for a set id would hide exactly the kind of
     * failure this project has already had to fix twice
     * (runbook-prode-sin-equipo, the directory-outage notices in
     * TitleEditorPage).
     *
     * @param array<string, mixed> $item
     */
    protected function column_vinculado_a( $item ): string {
        $jugadorId = $item['jugador_id'] ?? null;

        if ( null === $jugadorId ) {
            return '—';
        }

        if ( $this->directoryUnavailable ) {
            return esc_html__(
                'No se pudo verificar (directorio no disponible)',
                'entre-redes-campeones'
            );
        }

        if ( isset( $this->playerNamesById[ $jugadorId ] ) ) {
            return esc_html( $this->playerNamesById[ $jugadorId ] );
        }

        return esc_html(
            sprintf(
                /* translators: %d: the dangling sp_player id */
                __( 'ID %d — jugador no encontrado', 'entre-redes-campeones' ),
                $jugadorId
            )
        );
    }

    /**
     * The linked sp_player id, shown plainly (not just implied by the name)
     * so an operator can copy it into another row's "ID jugador" field to
     * relink by hand — that reuse is the explicit reason this column
     * exists. Always shown when set, even for a dangling pointer or during
     * a directory outage: the id itself never depends on the lookup.
     *
     * @param array<string, mixed> $item
     */
    protected function column_jugador_id( $item ): string {
        $jugadorId = $item['jugador_id'] ?? null;

        return null === $jugadorId ? '—' : esc_html( (string) $jugadorId );
    }

    /**
     * Editar (name / captain / order) plus Eliminar the row, plus the link
     * control (Vincular / Cambiar / Desvincular). One nonce per row, keyed
     * to the row id (LINK-8), shared by every form below — editar_fila
     * included. The forms are joined with a visible " | " separator
     * (design §6 amendment) — without it, "Cambiar Desvincular Eliminar"
     * ran together with no separation.
     *
     * @param array<string, mixed> $item
     */
    protected function column_acciones( $item ): string {
        $plantelId  = (int) $item['id'];
        $nonce      = wp_create_nonce( 'campeones_link_' . $plantelId );
        $adminUrl   = admin_url( 'admin.php?page=campeones-titulo-edit&titulo_id=' . $this->tituloId );
        $isLinked   = ! empty( $item['jugador_id'] );
        $linkLabel  = $isLinked ? __( 'Cambiar', 'entre-redes-campeones' ) : __( 'Vincular', 'entre-redes-campeones' );
        $linkAction = $isLinked ? 'cambiar' : 'vincular';
        $hiddenIds  = [ 'titulo_id' => $this->tituloId, 'plantel_id' => $plantelId ];

        $editarExtra = sprintf(
            '<input type="text" name="jugador_nombre" value="%s" style="width:12em;"> '
            . '<label><input type="checkbox" name="es_capitan" value="1"%s> %s</label>'
            . '<input type="hidden" name="orden" value="%d">',
            esc_attr( (string) ( $item['jugador_nombre'] ?? '' ) ),
            ! empty( $item['es_capitan'] ) ? ' checked' : '',
            esc_html__( 'Capitán', 'entre-redes-campeones' ),
            (int) ( $item['orden'] ?? 0 )
        );

        $parts   = [];
        $parts[] = ActionForm::render(
            'campeones_editor_action',
            'editar_fila',
            $adminUrl,
            $hiddenIds,
            'campeones_link_nonce',
            $nonce,
            __( 'Guardar', 'entre-redes-campeones' ),
            $editarExtra
        );

        $linkExtra = sprintf(
            '<input type="number" name="jugador_id" placeholder="%s" style="width:9em;">',
            esc_attr__( 'ID jugador', 'entre-redes-campeones' )
        );

        $parts[] = ActionForm::render(
            'campeones_editor_action',
            $linkAction,
            $adminUrl,
            $hiddenIds,
            'campeones_link_nonce',
            $nonce,
            $linkLabel,
            $linkExtra
        );

        if ( $isLinked ) {
            $parts[] = ActionForm::render(
                'campeones_editor_action',
                'desvincular',
                $adminUrl,
                $hiddenIds,
                'campeones_link_nonce',
                $nonce,
                __( 'Desvincular', 'entre-redes-campeones' )
            );
        }

        $parts[] = ActionForm::render(
            'campeones_editor_action',
            'eliminar_fila',
            $adminUrl,
            $hiddenIds,
            'campeones_link_nonce',
            $nonce,
            __( 'Eliminar', 'entre-redes-campeones' ),
            confirmMessage: __( '¿Eliminar esta fila del plantel?', 'entre-redes-campeones' ),
            buttonClass: 'button-link submitdelete'
        );

        return implode( ' | ', $parts );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_cb( $item ): string {
        return '';
    }

    public function no_items(): void {
        esc_html_e( 'Este título todavía no tiene jugadores cargados.', 'entre-redes-campeones' );
    }
}
