<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\PoliticaBloqueoReemplazo;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteNoBloqueado;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class EntranteNoBloqueadoTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_with_no_trunca_closures_anywhere(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entrantePlazasConCierreTruncado' => [] ] );

        $this->assertNull( ( new EntranteNoBloqueado() )->evaluar( $ctx ) );
    }

    // -------------------------------------------------------------------------
    // Default policy: TOPE_TRES_FECHAS
    // -------------------------------------------------------------------------

    public function test_default_policy_blocks_before_3_resolved_fechas_since_departure(): void {
        $otraPlaza = [
            $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 500, 'fecha_desde_id' => 1, 'fecha_hasta_id' => 5, 'cerrada_por' => 'reemplazada' ] ),
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 2, 'player_id' => 888, 'es_genesis' => 0, 'fecha_desde_id' => 5, 'fecha_hasta_id' => 7, 'cerrada_por' => 'trunca' ] ),
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 3, 'player_id' => 999, 'es_genesis' => 0, 'fecha_desde_id' => 7, 'fecha_hasta_id' => null ] ),
        ];

        $ctx = $this->ctxFavorableSustitucion(
            [
                'entrantePlazasConCierreTruncado' => [ $otraPlaza ],
                'countResolvedFechasSinceFn'       => static fn ( int $fechaId ): int => 7 === $fechaId ? 2 : 10,
            ]
        );

        $motivo = ( new EntranteNoBloqueado() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'entrante_bloqueado_por_cierre_truncado', $motivo->codigo() );
    }

    public function test_default_policy_unblocks_at_3_resolved_fechas_since_departure(): void {
        $otraPlaza = [
            $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 500, 'fecha_desde_id' => 1, 'fecha_hasta_id' => 5, 'cerrada_por' => 'reemplazada' ] ),
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 2, 'player_id' => 888, 'es_genesis' => 0, 'fecha_desde_id' => 5, 'fecha_hasta_id' => 7, 'cerrada_por' => 'trunca' ] ),
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 3, 'player_id' => 999, 'es_genesis' => 0, 'fecha_desde_id' => 7, 'fecha_hasta_id' => null ] ),
        ];

        $ctx = $this->ctxFavorableSustitucion(
            [
                'entrantePlazasConCierreTruncado' => [ $otraPlaza ],
                'countResolvedFechasSinceFn'       => static fn ( int $fechaId ): int => 7 === $fechaId ? 3 : 10,
            ]
        );

        $this->assertNull( ( new EntranteNoBloqueado() )->evaluar( $ctx ) );
    }

    public function test_default_policy_fails_closed_when_the_counter_throws(): void {
        $otraPlaza = [
            $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 888, 'fecha_desde_id' => 1, 'fecha_hasta_id' => 7, 'cerrada_por' => 'trunca' ] ),
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 2, 'player_id' => 999, 'es_genesis' => 0, 'fecha_desde_id' => 7, 'fecha_hasta_id' => null ] ),
        ];

        $ctx = $this->ctxFavorableSustitucion(
            [
                'entrantePlazasConCierreTruncado' => [ $otraPlaza ],
                'countResolvedFechasSinceFn'       => static function ( int $fechaId ): int {
                    throw new \RuntimeException( 'no se pudo contar' );
                },
            ]
        );

        $motivo = ( new EntranteNoBloqueado() )->evaluar( $ctx );

        $this->assertNotNull( $motivo, 'An uncountable answer must never read as "already unblocked".' );
    }

    public function test_not_blocked_when_the_entrante_has_no_trunca_closure_in_that_other_plaza(): void {
        $otraPlaza = [
            $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 500, 'fecha_desde_id' => 1, 'fecha_hasta_id' => 5, 'cerrada_por' => 'reemplazada' ] ),
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 2, 'player_id' => 501, 'es_genesis' => 0, 'fecha_desde_id' => 5, 'fecha_hasta_id' => null ] ),
        ];

        $ctx = $this->ctxFavorableSustitucion( [ 'entrantePlazasConCierreTruncado' => [ $otraPlaza ] ] );

        $this->assertNull( ( new EntranteNoBloqueado() )->evaluar( $ctx ) );
    }

    public function test_does_not_apply_to_a_regreso(): void {
        $ctx = $this->ctxFavorableRegreso(
            [
                'entrantePlazasConCierreTruncado' => [
                    [ $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 777, 'fecha_hasta_id' => 1, 'cerrada_por' => 'trunca' ] ) ],
                ],
            ]
        );

        $this->assertNull( ( new EntranteNoBloqueado() )->evaluar( $ctx ) );
    }

    // -------------------------------------------------------------------------
    // CC5b — the same scenario, both policies, two different verdicts. This
    // is the test that documents CC5b is a POLICY DECISION, not a fact: the
    // plaza the entrante left had ANOTHER trunca closure after theirs, which
    // pushes HASTA_LIBERACION_DE_PLAZA's answer out further than the
    // entrante's own personal 3-fecha window under TOPE_TRES_FECHAS.
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function otraPlazaConDobleCierreTruncado(): array {
        return [
            $this->ocupacion( [ 'plaza_id' => 2, 'player_id' => 500, 'fecha_desde_id' => 1, 'fecha_hasta_id' => 5, 'cerrada_por' => 'reemplazada' ] ),
            // The entrante's OWN trunca closure — left at fecha 7, long ago.
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 2, 'player_id' => 888, 'es_genesis' => 0, 'fecha_desde_id' => 5, 'fecha_hasta_id' => 7, 'cerrada_por' => 'trunca' ] ),
            // A SECOND occupant also left trunca, later — this is what keeps
            // the PLAZA itself from liberating even though the entrante's own
            // personal window has long cleared.
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 3, 'player_id' => 600, 'es_genesis' => 0, 'fecha_desde_id' => 7, 'fecha_hasta_id' => 9, 'cerrada_por' => 'trunca' ] ),
            // Currently vigent — has not yet cleared its own mínimo.
            $this->ocupacion( [ 'plaza_id' => 2, 'id' => 4, 'player_id' => 999, 'es_genesis' => 0, 'fecha_desde_id' => 9, 'fecha_hasta_id' => null ] ),
        ];
    }

    public function test_tope_tres_fechas_unblocks_once_the_entrante_own_window_cleared(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entrantePlazasConCierreTruncado' => [ $this->otraPlazaConDobleCierreTruncado() ],
                'countResolvedFechasSinceFn'       => static fn ( int $fechaId ): int => [ 7 => 10, 9 => 1 ][ $fechaId ] ?? 0,
            ]
        );

        $regla = new EntranteNoBloqueado( PoliticaBloqueoReemplazo::topeTresFechas() );

        $this->assertNull(
            $regla->evaluar( $ctx ),
            'TOPE_TRES_FECHAS only counts from the entrante\'s OWN departure (fecha 7), long cleared.'
        );
    }

    public function test_hasta_liberacion_de_plaza_keeps_blocking_the_same_scenario(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'entrantePlazasConCierreTruncado' => [ $this->otraPlazaConDobleCierreTruncado() ],
                'countResolvedFechasSinceFn'       => static fn ( int $fechaId ): int => [ 7 => 10, 9 => 1 ][ $fechaId ] ?? 0,
            ]
        );

        $regla = new EntranteNoBloqueado( PoliticaBloqueoReemplazo::hastaLiberacionDePlaza() );

        $motivo = $regla->evaluar( $ctx );

        $this->assertNotNull(
            $motivo,
            'HASTA_LIBERACION_DE_PLAZA anchors to the PLAZA\'s own liberation (fecha 9), not yet cleared.'
        );
        $this->assertSame( 'entrante_bloqueado_por_cierre_truncado', $motivo->codigo() );
    }
}
