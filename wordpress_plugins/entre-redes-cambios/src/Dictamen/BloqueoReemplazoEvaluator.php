<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * Pure evaluator of "is THIS player blocked from re-entering a plaza, given
 * one specific OTHER plaza's chain and a BloqueoReemplazoPolicy" — extracted
 * out of Reglas\EntranteNoBloqueado (slice 4) so it can be reused by
 * Plazas\CandidatosResolver without either copying this logic or making
 * CandidatosResolver re-derive its own reading of CC5b.
 *
 * *** WHY THIS EXISTS AT ALL: "THE RESOLVER AND THE RULE MUST AGREE" ***
 * The prioridad-de-padres feature (see Reglas\PrioridadDePadresRespetada) asks
 * a question no existing class answered end to end: "of every candidate for
 * this plaza, which ones are actually blocked?" Reglas\EntranteNoBloqueado
 * already answered exactly that question, but only for ONE named entrante
 * pulled from a DictamenContext. Duplicating its algorithm inside
 * CandidatosResolver would create two independent implementations of the same
 * business rule that could silently drift apart — a captain's screen (fed by
 * the resolver) disagreeing with the actual dictamen (fed by the rule) is
 * worse than not having either. Both now call this ONE class.
 *
 * Zero DB, zero clock, exactly like the class it was extracted from — the
 * caller supplies the chain and the counter callable.
 */
final class BloqueoReemplazoEvaluator {

    /** How many resolved fechas TOPE_TRES_FECHAS caps the block at. */
    private const TOPE_FECHAS = 3;

    /**
     * Whether $playerId is blocked from re-entering under $politica, given
     * ONE other plaza's full ocupaciones chain — the exact per-chain check
     * Reglas\EntranteNoBloqueado::isBlockedAt() used to perform inline.
     *
     * @param array<int, array<string, mixed>> $ocupacionesDeOtraPlaza The
     *        other plaza's full chain, as PlazaRepository::listOcupaciones()
     *        returns it.
     * @param callable(int): int $countResolvedFechasSinceFn Same contract as
     *        DictamenContext::countResolvedFechasSinceFn() / CadenaResolver's
     *        constructor parameter.
     */
    public function isBlocked(
        int $playerId,
        array $ocupacionesDeOtraPlaza,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn
    ): bool {
        if ( $politica->isHastaLiberacionDePlaza() ) {
            $resolver = new \EntreRedes\Cambios\Plazas\CadenaResolver( $countResolvedFechasSinceFn );

            return in_array( $playerId, $resolver->listExOcupantesBloqueados( $ocupacionesDeOtraPlaza ), true );
        }

        // TOPE_TRES_FECHAS: blocked only for whatever remains of 3 fechas
        // counted from the moment the entrante LEFT (fecha_hasta_id) that
        // other plaza — independent of that plaza's own liberation.
        $cierreTrunco = $this->truncatedClosureOf( $playerId, $ocupacionesDeOtraPlaza );

        if ( null === $cierreTrunco ) {
            return false;
        }

        try {
            $resueltas = $countResolvedFechasSinceFn( (int) $cierreTrunco['fecha_hasta_id'] );
        } catch ( \Throwable $e ) {
            // Fail closed, same discipline as CadenaResolver: an uncountable
            // answer must never be read as "already unblocked".
            return true;
        }

        if ( $resueltas < 0 ) {
            return true;
        }

        return $resueltas < self::TOPE_FECHAS;
    }

    /**
     * Whether $playerId is blocked in AT LEAST ONE of several other plazas'
     * chains — the exact aggregation Reglas\EntranteNoBloqueado::evaluate()
     * used to perform over DictamenContext::entrantePlazasConCierreTruncado().
     *
     * @param array<int, array<int, array<string, mixed>>> $plazasConCierreTruncado
     * @param callable(int): int                            $countResolvedFechasSinceFn
     */
    public function isBlockedEnAlguna(
        int $playerId,
        array $plazasConCierreTruncado,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn
    ): bool {
        foreach ( $plazasConCierreTruncado as $ocupacionesDeOtraPlaza ) {
            if ( $this->isBlocked( $playerId, $ocupacionesDeOtraPlaza, $politica, $countResolvedFechasSinceFn ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array<string, mixed>|null
     */
    private function truncatedClosureOf( int $playerId, array $ocupaciones ): ?array {
        foreach ( $ocupaciones as $ocupacion ) {
            if ( $playerId === (int) $ocupacion['player_id'] && 'trunca' === ( $ocupacion['cerrada_por'] ?? null ) ) {
                return $ocupacion;
            }
        }

        return null;
    }
}
