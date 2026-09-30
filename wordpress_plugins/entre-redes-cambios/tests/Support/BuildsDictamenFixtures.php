<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Support;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * Shared fixture builders for every Dictamen test: a default, fully-favorable
 * scenario (a `sustitucion` — and separately a `regreso` — that clears every
 * rule) that each test overrides only the ONE field its rule actually cares
 * about. Keeping every other field at a known-good default is what keeps
 * "one rule, one focused test" honest: a test failing for the wrong reason
 * (a stale, unrelated default) is exactly what a fully-specified baseline
 * avoids.
 */
trait BuildsDictamenFixtures {

    private function epoch( string $utcDatetime ): int {
        return ( new \DateTimeImmutable( $utcDatetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function plaza( array $overrides = [] ): array {
        return array_merge(
            [
                'id'                => 1,
                'season_id'         => 1,
                'team_id'           => 10,
                'titular_player_id' => 777,
                'puntaje_techo'     => 6, // Puntaje::fromDecimal(3.0)
                'created_at'        => '2026-01-01 00:00:00',
                'closed_at'         => null,
            ],
            $overrides
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function ocupacion( array $overrides = [] ): array {
        return array_merge(
            [
                'id'             => 1,
                'plaza_id'       => 1,
                'player_id'      => 777,
                'es_genesis'     => 1,
                'fecha_desde_id' => 1,
                'fecha_hasta_id' => null,
                'cerrada_por'    => null,
                'created_at'     => '2026-01-01 00:00:00',
            ],
            $overrides
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function plazosUtc( array $overrides = [] ): array {
        return array_merge(
            [
                'apertura_solicitudes' => '2026-01-01 00:00:00',
                'cierre_regresos'      => '2026-01-06 23:59:59',
                'cierre_solicitudes'   => '2026-01-08 23:59:59',
                'publicacion'          => '2026-01-09 00:00:00',
            ],
            $overrides
        );
    }

    /** @param array<string, mixed> $overrides */
    private function solicitudSustitucion( array $overrides = [] ): SolicitudDeCambio {
        $o = array_merge(
            [
                'seasonId'         => 1,
                'teamId'           => 10,
                'plazaId'          => 1,
                'entrantePlayerId' => 888,
                'fechaId'          => 5,
                'instanteEpoch'    => $this->epoch( '2026-01-03 12:00:00' ),
            ],
            $overrides
        );

        return SolicitudDeCambio::sustitucion(
            $o['seasonId'],
            $o['teamId'],
            $o['plazaId'],
            $o['entrantePlayerId'],
            $o['fechaId'],
            $o['instanteEpoch']
        );
    }

    /** @param array<string, mixed> $overrides */
    private function solicitudRegreso( array $overrides = [] ): SolicitudDeCambio {
        $o = array_merge(
            [
                'seasonId'      => 1,
                'teamId'        => 10,
                'plazaId'       => 1,
                'fechaId'       => 5,
                'instanteEpoch' => $this->epoch( '2026-01-03 12:00:00' ),
            ],
            $overrides
        );

        return SolicitudDeCambio::regreso(
            $o['seasonId'],
            $o['teamId'],
            $o['plazaId'],
            $o['fechaId'],
            $o['instanteEpoch']
        );
    }

    /**
     * A fully-favorable context: a `sustitucion` into a plaza vigently
     * occupied by the titular (777), entrante 888 with puntaje 2.5 (well
     * within a techo of 3.0), no other occupations anywhere, no trunca
     * closures, and an instante inside every plazo window.
     *
     * @param array<string, mixed> $overrides Keys: solicitud, plaza,
     *        ocupaciones, entrantePuntaje, entranteOcupacionesEnOtrasPlazas,
     *        entrantePlazasConCierreTruncado, plazosUtc,
     *        countResolvedFechasSinceFn.
     */
    private function ctxFavorableSustitucion( array $overrides = [] ): DictamenContext {
        return $this->ctx(
            array_merge(
                [
                    'solicitud'       => $this->solicitudSustitucion(),
                    'ocupaciones'     => [ $this->ocupacion() ],
                    'entrantePuntaje' => Puntaje::fromDecimal( 2.5 ),
                ],
                $overrides
            )
        );
    }

    /**
     * A `regreso` variant: solicitud is `regreso`, entrante is null, and the
     * plaza's vigent occupant is a suplente (999, since fecha 9), NOT the
     * titular — a regreso against a plaza the titular already occupies is a
     * degenerate no-op (see Reglas\RegresoSoloConMinimoCumplido's docblock).
     * The suplente has long cleared the mínimo by default (10 resolved
     * fechas since fecha 9), so the titular may return.
     *
     * @param array<string, mixed> $overrides
     */
    private function ctxFavorableRegreso( array $overrides = [] ): DictamenContext {
        return $this->ctx(
            array_merge(
                [
                    'solicitud'   => $this->solicitudRegreso(),
                    'ocupaciones' => [
                        $this->ocupacion( [ 'fecha_hasta_id' => 9, 'cerrada_por' => 'reemplazada' ] ),
                        $this->ocupacion(
                            [
                                'id'             => 2,
                                'player_id'      => 999,
                                'es_genesis'     => 0,
                                'fecha_desde_id' => 9,
                                'fecha_hasta_id' => null,
                                'cerrada_por'    => null,
                            ]
                        ),
                    ],
                    'countResolvedFechasSinceFn' => static fn ( int $fechaId ): int => 10,
                ],
                $overrides
            )
        );
    }

    /**
     * Raw builder — every field defaulted to the favorable-sustitucion
     * baseline; ctxFavorableSustitucion()/ctxFavorableRegreso() are the ones
     * tests should normally reach for.
     *
     * @param array<string, mixed> $overrides
     */
    private function ctx( array $overrides = [] ): DictamenContext {
        $defaults = [
            'solicitud'                       => $this->solicitudSustitucion(),
            'plaza'                           => $this->plaza(),
            'ocupaciones'                     => [ $this->ocupacion() ],
            'entrantePuntaje'                 => Puntaje::fromDecimal( 2.5 ),
            'entranteOcupacionesEnOtrasPlazas' => [],
            'entrantePlazasConCierreTruncado' => [],
            'plazosUtc'                       => $this->plazosUtc(),
            'countResolvedFechasSinceFn'      => static fn ( int $fechaId ): int => 10,
            // Both default OFF/0 — the "prioridad de padres" policy is off
            // by default (see Migrations\InitialSchema::SEED_DEFAULTS), so
            // the favorable baseline every OTHER rule's test relies on must
            // stay unaffected unless a test explicitly overrides these.
            'entranteEsPadre'                 => false,
            'padresViablesParaLaPlaza'        => 0,
        ];

        $o = array_merge( $defaults, $overrides );

        return new DictamenContext(
            $o['solicitud'],
            $o['plaza'],
            $o['ocupaciones'],
            $o['entrantePuntaje'],
            $o['entranteOcupacionesEnOtrasPlazas'],
            $o['entrantePlazasConCierreTruncado'],
            $o['plazosUtc'],
            $o['countResolvedFechasSinceFn'],
            $o['entranteEsPadre'],
            $o['padresViablesParaLaPlaza']
        );
    }
}
