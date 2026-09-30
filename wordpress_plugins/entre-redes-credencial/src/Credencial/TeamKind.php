<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Credencial;

/**
 * Classifies a resolved team/list name into `team.kind` (design D15). A
 * player's "team" MAY actually be a waiting or non-enrolled list acting as a
 * team (spec "Credential Payload and Display": "Lista de Espera 2026",
 * "Lista de No Inscriptos 2026") — this class is the ONLY place that
 * distinguishes the two from a real team, so the Flutter app never has to
 * parse a name itself (design D15: "Works offline").
 *
 * Matching is prefix-based, case-insensitive, and tolerant of the messy
 * whitespace/dash variants this codebase's own list/league names actually
 * use in production (regular hyphen, en dash U+2013, em dash U+2014 — see
 * this project's own engram note on 2026 Clausura league names using U+2013)
 * — a name is normalized before the prefix check, never matched raw.
 */
final class TeamKind {

    public const TEAM           = 'team';
    public const WAITING_LIST   = 'waiting_list';
    public const NOT_REGISTERED = 'not_registered';

    private const PREFIX_WAITING_LIST   = 'LISTA DE ESPERA';
    private const PREFIX_NOT_REGISTERED = 'LISTA DE NO INSCRIPTOS';

    public static function fromName( string $name ): string {
        $normalized = self::normalize( $name );

        if ( str_starts_with( $normalized, self::PREFIX_NOT_REGISTERED ) ) {
            return self::NOT_REGISTERED;
        }

        if ( str_starts_with( $normalized, self::PREFIX_WAITING_LIST ) ) {
            return self::WAITING_LIST;
        }

        return self::TEAM;
    }

    /**
     * Uppercases, replaces every dash variant with a plain space, collapses
     * runs of whitespace to a single space, and trims — so "Lista–de Espera",
     * "Lista - de Espera" and "Lista   de   Espera" all normalize to the same
     * "LISTA DE ESPERA" prefix.
     */
    private static function normalize( string $name ): string {
        $withoutDashes = str_replace( [ "\u{2013}", "\u{2014}", '-' ], ' ', $name );
        $collapsed     = preg_replace( '/\s+/u', ' ', $withoutDashes ) ?? $withoutDashes;

        return strtoupper( trim( $collapsed ) );
    }
}
