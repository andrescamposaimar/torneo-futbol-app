<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * PENDING CONFIRMATION FROM THE PROCESS OWNER (CC5b) — see
 * Reglas\EntranteNoBloqueado's class docblock for the full rationale. This
 * class only models the two candidate readings; it does not resolve which
 * one is correct.
 *
 * The reglamento's own text — "no podrá ir como reemplazo a otro equipo
 * hasta que no haya transcurrido lo que reste de las 3 fechas" — reads as a
 * SHORT, capped window: whatever remains of 3 fechas from the moment the
 * reemplazo left. But the practice described anchors the block to the
 * REPLACEMENT'S own liberation instead, and since occupations renew by
 * silence with no maximum duration (Plazas\CadenaResolver's class
 * docblock), that anchor can push the block out indefinitely — a player
 * hurt in week 2 could stay blocked for months, not fechas.
 *
 * `TOPE_TRES_FECHAS` is the DEFAULT (see EntranteNoBloqueado's constructor)
 * precisely because it is the less severe reading and the one with more
 * direct textual support: if this system is going to be wrong about a
 * pending question, it must be wrong in the direction of letting someone
 * play, never in the direction of benching them for months over a plain
 * ambiguity nobody has resolved yet.
 */
final class PoliticaBloqueoReemplazo {

    private const TOPE_TRES_FECHAS          = 'tope_tres_fechas';
    private const HASTA_LIBERACION_DE_PLAZA = 'hasta_liberacion_de_plaza';

    private string $valor;

    private function __construct( string $valor ) {
        $this->valor = $valor;
    }

    /**
     * The reglamento's literal text: blocked only for whatever remains of 3
     * fechas counted from the moment the reemplazo left the OTHER plaza
     * trunca — independent of when (or whether) that other plaza itself
     * ever liberates.
     */
    public static function topeTresFechas(): self {
        return new self( self::TOPE_TRES_FECHAS );
    }

    /**
     * The practice described to the team: blocked until the SAME plaza the
     * reemplazo left liberates — i.e. exactly
     * Plazas\CadenaResolver::listExOcupantesBloqueados()'s existing answer
     * for that plaza, reused as-is.
     */
    public static function hastaLiberacionDePlaza(): self {
        return new self( self::HASTA_LIBERACION_DE_PLAZA );
    }

    public function esTopeTresFechas(): bool {
        return self::TOPE_TRES_FECHAS === $this->valor;
    }

    public function esHastaLiberacionDePlaza(): bool {
        return self::HASTA_LIBERACION_DE_PLAZA === $this->valor;
    }
}
