<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\ContextoDeDictamen;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\PoliticaBloqueoReemplazo;
use EntreRedes\Cambios\Dictamen\Regla;
use EntreRedes\Cambios\Plazas\CadenaResolver;

/**
 * The entrante of a `sustitucion` cannot be blocked by having left ANOTHER
 * plaza `trunca` (before meeting the 3-fecha mínimo) — see
 * Plazas\CadenaResolver's class docblock for what `trunca` means and why the
 * whole plaza (not the individual ex-occupant) liberates at once.
 *
 * *** CC5b — WHICH POLICY DECIDES HOW LONG THE BLOCK LASTS IS INJECTED, NOT
 * HARDCODED *** See PoliticaBloqueoReemplazo's class docblock for the full
 * rationale of the ambiguity and why `topeTresFechas()` is the default. This
 * class exists specifically so that policy is a constructor parameter, never
 * an `if` buried in a method body — a later confirmation from the process
 * owner should be a one-line change at whoever wires MotorDeDictamen's rules
 * together, not a code change here.
 *
 * `ContextoDeDictamen::entrantePlazasConCierreTruncado()` hands this rule
 * the FULL chain of every other plaza where the entrante has a trunca
 * closure — this rule evaluates the injected policy against every one of
 * them and blocks on the first match; an entrante with no trunca closures
 * anywhere is never blocked, trivially.
 */
final class EntranteNoBloqueado implements Regla {

    private const CODE = 'entrante_bloqueado_por_cierre_truncado';

    /** How many resolved fechas TOPE_TRES_FECHAS caps the block at. */
    private const TOPE_FECHAS = 3;

    private PoliticaBloqueoReemplazo $politica;

    public function __construct( ?PoliticaBloqueoReemplazo $politica = null ) {
        $this->politica = $politica ?? PoliticaBloqueoReemplazo::topeTresFechas();
    }

    public function evaluar( ContextoDeDictamen $ctx ): ?Motivo {
        $entrantePlayerId = $ctx->solicitud()->entrantePlayerId();

        if ( null === $entrantePlayerId ) {
            return null;
        }

        foreach ( $ctx->entrantePlazasConCierreTruncado() as $ocupacionesDeOtraPlaza ) {
            if ( $this->bloqueadoEn( $entrantePlayerId, $ocupacionesDeOtraPlaza, $ctx ) ) {
                return new Motivo(
                    self::CODE,
                    'El entrante está bloqueado por haber dejado trunca otra plaza — ver CC5b (política pendiente de confirmación).'
                );
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $ocupaciones The other plaza's
     *        full chain.
     */
    private function bloqueadoEn( int $entrantePlayerId, array $ocupaciones, ContextoDeDictamen $ctx ): bool {
        if ( $this->politica->esHastaLiberacionDePlaza() ) {
            $resolver = new CadenaResolver( $ctx->countResolvedFechasSinceFn() );

            return in_array( $entrantePlayerId, $resolver->listExOcupantesBloqueados( $ocupaciones ), true );
        }

        // TOPE_TRES_FECHAS: blocked only for whatever remains of 3 fechas
        // counted from the moment the entrante LEFT (fecha_hasta_id) that
        // other plaza — independent of that plaza's own liberation.
        $cierreTrunco = $this->cierreTruncoDe( $entrantePlayerId, $ocupaciones );

        if ( null === $cierreTrunco ) {
            return false;
        }

        try {
            $resueltas = ( $ctx->countResolvedFechasSinceFn() )( (int) $cierreTrunco['fecha_hasta_id'] );
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
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array<string, mixed>|null
     */
    private function cierreTruncoDe( int $playerId, array $ocupaciones ): ?array {
        foreach ( $ocupaciones as $ocupacion ) {
            if ( $playerId === (int) $ocupacion['player_id'] && 'trunca' === ( $ocupacion['cerrada_por'] ?? null ) ) {
                return $ocupacion;
            }
        }

        return null;
    }
}
