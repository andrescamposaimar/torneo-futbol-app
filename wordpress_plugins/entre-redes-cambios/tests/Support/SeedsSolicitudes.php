<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Support;

/**
 * Inserts a raw `cambios_solicitud` row, bypassing `SolicitudRepository`
 * entirely. The open-request tests need a request in an ARBITRARY estado
 * (`publicada`, `anulada`…) and, for the grouped case, with arbitrary
 * `plaza_campo_id` / `entrante_campo_player_id` — going through
 * `crear()` + the state machine for each would test the repository's
 * plumbing instead of the one thing under test: which rows count as open.
 *
 * Requires the using TestCase to have run `InitialSchema::up()`.
 */
trait SeedsSolicitudes {

    /**
     * @param array<string, mixed> $o Overrides: season_id, team_id,
     *        plaza_id, tipo, estado, entrante_player_id,
     *        plaza_campo_id, entrante_campo_player_id.
     * @return int The new row's id.
     */
    private function seedSolicitud( array $o = [] ): int {
        global $wpdb;

        $o = array_merge(
            [
                'season_id'                => 359,
                'team_id'                  => 100,
                'plaza_id'                 => 1,
                'tipo'                     => 'sustitucion',
                'estado'                   => 'pendiente',
                'entrante_player_id'       => 888,
                'plaza_campo_id'           => null,
                'entrante_campo_player_id' => null,
            ],
            $o
        );

        $wpdb->insert(
            $wpdb->prefix . 'cambios_solicitud',
            [
                'season_id'                => $o['season_id'],
                'team_id'                  => $o['team_id'],
                'plaza_id'                 => $o['plaza_id'],
                'tipo'                     => $o['tipo'],
                'entrante_player_id'       => $o['entrante_player_id'],
                'plaza_campo_id'           => $o['plaza_campo_id'],
                'entrante_campo_player_id' => $o['entrante_campo_player_id'],
                'fecha_id'                 => 1,
                'solicitada_por'           => 777,
                'solicitada_at'            => '2026-03-01 10:00:00',
                'solicitud_instante_epoch' => 1772359200,
                'dictamen_original'        => '{}',
                'estado'                   => $o['estado'],
                'created_at'               => '2026-03-01 10:00:00',
                'updated_at'               => '2026-03-01 10:00:00',
            ]
        );

        return (int) $wpdb->insert_id;
    }
}
