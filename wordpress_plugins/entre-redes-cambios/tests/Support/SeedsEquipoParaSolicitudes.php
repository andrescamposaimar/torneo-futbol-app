<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Support;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;

/**
 * One real team, ready to receive solicitudes — the fixture the
 * `CrearSolicitud` use case and the admin "Nueva solicitud" page both need,
 * built once so the two suites cannot drift apart.
 *
 * Team 100 of season 359 has two plazas: the goal plaza (titular 111, the
 * goalkeeper, techo 2.5) and ONE field plaza (titular 222, techo 3.0), plus a
 * fecha 5 three days after `$fixedNow` — inside the default solicitud window.
 * Players 333 (2.5) and 444 (2.0) are outside players with a puntaje.
 *
 * Real repositories over the shared SQLite wpdb, never mocks: the properties
 * under test (a row lands `pendiente`, a duplicate stores nothing) are only
 * provable against real persistence.
 */
trait SeedsEquipoParaSolicitudes {

    private int $seasonId        = 359;
    private int $teamId          = 100;
    private int $fechaId         = 5;
    private int $arqueroPlayerId = 111;
    private int $titularPlayerId = 222;
    private int $entrantePlayerId = 333;
    private int $otroEntrantePlayerId = 444;

    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private DictamenPipeline $pipeline;
    private SolicitudRepository $solicitudRepository;
    private JugadorMetricasReader $jugadorMetricasReader;
    private int $plazaArcoId;
    private int $plazaCampoId;
    private int $fixedNow;

    private function armarEquipo(): void {
        InitialSchema::up();

        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;

        foreach ( $this->tablasDelEquipo() as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        $this->eventLog              = new InMemoryEventLog();
        $this->plazaRepository       = new PlazaRepository( $wpdb, $this->eventLog );
        $this->fechaRepository       = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $this->settings              = new Settings( $wpdb, $this->eventLog );
        $assembler                   = new DictamenContextAssembler( $this->plazaRepository, $this->fechaRepository, $this->settings, $wpdb, $this->eventLog );
        $this->pipeline              = new DictamenPipeline( $assembler, $this->eventLog, null, false, $this->settings->exencionArcoActiva() );
        $this->solicitudRepository   = new SolicitudRepository( $wpdb, $this->plazaRepository, $this->pipeline, $this->eventLog );
        $this->jugadorMetricasReader = new JugadorMetricasReader( $wpdb, $this->eventLog );

        // Genesis fecha: satisfies openPlaza()'s assertFechaExistsInSeason().
        $this->seedFecha( 1, '2026-01-01' );

        $wp_test_position_terms = [ $this->arqueroPlayerId => [ 3 ] ]; // term 3 = goalkeeper
        $this->plazaArcoId      = $this->plazaRepository->openPlaza(
            $this->seasonId, $this->teamId, $this->arqueroPlayerId, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00'
        );

        $wp_test_position_terms = []; // 222 is an ordinary field player
        $this->plazaCampoId     = $this->plazaRepository->openPlaza(
            $this->seasonId, $this->teamId, $this->titularPlayerId, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00'
        );

        $this->seedPuntaje( $this->titularPlayerId, 3.0 );
        $this->seedPuntaje( $this->entrantePlayerId, 2.5 );
        $this->seedPuntaje( $this->otroEntrantePlayerId, 2.0 );

        // A FIXED instant, never the real clock.
        $this->fixedNow = ( new \DateTimeImmutable( '2026-05-20 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $this->seedFecha( $this->fechaId, gmdate( 'Y-m-d', $this->fixedNow + 3 * DAY_IN_SECONDS ) );
    }

    private function limpiarEquipo(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;

        foreach ( $this->tablasDelEquipo() as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wp_test_position_terms = [];
        InitialSchema::up(); // restore settings seeds for any test file that runs after this one
    }

    /** @return string[] */
    private function tablasDelEquipo(): array {
        return [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_capitan', 'postmeta', 'cambios_settings' ];
    }

    private function seedFecha( int $fechaId, string $playDate ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $this->seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => 'programada',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    private function seedPuntaje( int $playerId, float $puntaje ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( [ 'puntaje' => (string) $puntaje ] ),
            ]
        );
    }

    private function countSolicitudes(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_solicitud" );
    }
}
