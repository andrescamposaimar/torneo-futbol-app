<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

/**
 * Builds one self-contained inline POST form for an admin list table row
 * action: a hidden action field, any number of other hidden id fields, a
 * hidden nonce, an optional extra markup fragment (a per-form input, e.g.
 * the raw player-id field on Vincular), a submit button, and an optional
 * JS confirm() guard on destructive actions.
 *
 * Extracted (item 11) from five near-duplicate blocks — two in
 * TitlesListTable::column_acciones() (Revalidar, Eliminar), three in
 * SquadListTable::column_acciones() (Vincular/Cambiar, Desvincular,
 * Eliminar) plus the fourth added for Editar — all the same shape that
 * would otherwise keep getting copied forward into every future slice's
 * list table. Deliberately done LAST, after every behavioural fix in this
 * file landed, so the extraction is verified by tests that already pass
 * rather than the other way around.
 */
final class ActionForm {

    /**
     * @param array<string, int|string> $hiddenFields Hidden name => value
     *        pairs beyond the action itself — e.g. titulo_id, plantel_id.
     *
     * Calling convention (item 8): every call site in this codebase passes
     * $confirmMessage / $buttonClass by NAME, never positionally, and ONLY
     * when it also skips $extraHtml (its default, ''). A call that needs
     * $extraHtml (a form with its own inline input — Editar, Vincular /
     * Cambiar) passes every parameter up to and including $extraHtml
     * positionally instead. This is a rule, not an inconsistency: named
     * args exist here specifically to skip an unused optional parameter
     * without repeating its default, never to skip past one that is
     * actually needed.
     */
    public static function render(
        string $actionFieldName,
        string $actionValue,
        string $url,
        array $hiddenFields,
        string $nonceFieldName,
        string $nonce,
        string $buttonLabel,
        string $extraHtml = '',
        ?string $confirmMessage = null,
        string $buttonClass = 'button-link'
    ): string {
        $hidden = sprintf(
            '<input type="hidden" name="%s" value="%s">',
            esc_attr( $actionFieldName ),
            esc_attr( $actionValue )
        );

        foreach ( $hiddenFields as $name => $value ) {
            $hidden .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr( (string) $name ),
                esc_attr( (string) $value )
            );
        }

        $hidden .= sprintf(
            '<input type="hidden" name="%s" value="%s">',
            esc_attr( $nonceFieldName ),
            esc_attr( $nonce )
        );

        $onsubmit = null !== $confirmMessage
            ? sprintf( ' onsubmit="return confirm(\'%s\');"', esc_js( $confirmMessage ) )
            : '';

        return sprintf(
            '<form method="post" action="%s" style="display:inline;"%s>%s%s<button type="submit" class="%s">%s</button></form>',
            esc_url( $url ),
            $onsubmit,
            $hidden,
            $extraHtml,
            esc_attr( $buttonClass ),
            esc_html( $buttonLabel )
        );
    }
}
