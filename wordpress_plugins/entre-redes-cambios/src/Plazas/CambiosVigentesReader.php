<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Calendario\BoundedFechaCounter;
use EntreRedes\Cambios\Calendario\FechaAbierta;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;

/**
 * "Which substitutions are live right now, and what is the situation of each
 * one?" — the single source of truth behind BOTH the process owner's admin
 * "Cambios vigentes" table (`Admin\HistoricoPage`) and the captains' read-only
 * `GET /cambios/vigentes` screen (`Rest\VigentesController`).
 *
 * It was extracted from `HistoricoPage` so the two surfaces can never drift:
 * "Situación" is a derived fact (open solicitud / missing fechas / liberable /
 * unknown) and a second implementation of it is exactly how a captain would
 * end up reading "Faltan 2 fechas" while the comisión reads something else.
 *
 * Two levels:
 *  - `listar()` — the raw rows the admin renders, byte-for-byte what
 *    `HistoricoPage::construirVigentes()` used to build.
 *  - `listarPublico()` — the same rows shaped for the API: titular as
 *    `baja`, current occupant as `alta`, puntaje per player, the fecha as a
 *    label, and the situación as plain text.
 *
 * Names are the raw WP post titles ("Apellido, Nombre"), exactly like every
 * other endpoint of this plugin; presentation (flipping the comma) is the
 * client's job.
 *
 * Puntaje: `JugadorMetricasReader` already maps a stored 0 (or any
 * unreadable value) to `null`; here that becomes `puntaje: null` +
 * `sin_calificar: true`, never a fabricated 0.
 */
class CambiosVigentesReader {

    public const SITUACION_SOLICITUD_ABIERTA = 'solicitud_abierta';
    public const SITUACION_INDETERMINADO     = 'indeterminado';
    public const SITUACION_FALTAN_FECHAS     = 'faltan_fechas';
    public const SITUACION_PUEDE_VOLVER      = 'puede_volver';
    public const SITUACION_RENOVADO          = 'renovado';

    private PlazaRepository $plazaRepository;
    private SolicitudesAbiertasReader $solicitudesAbiertas;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private EventLog $eventLog;
    private ?JugadorMetricasReader $metricasReader;

    /** @var callable(): int */
    private $clockFn;

    public function __construct(
        PlazaRepository $plazaRepository,
        SolicitudesAbiertasReader $solicitudesAbiertas,
        FechaRepository $fechaRepository,
        Settings $settings,
        EventLog $eventLog,
        ?JugadorMetricasReader $metricasReader = null,
        ?callable $clockFn = null
    ) {
        $this->plazaRepository     = $plazaRepository;
        $this->solicitudesAbiertas = $solicitudesAbiertas;
        $this->fechaRepository     = $fechaRepository;
        $this->settings            = $settings;
        $this->eventLog            = $eventLog;
        $this->metricasReader      = $metricasReader;
        $this->clockFn             = $clockFn ?? static fn (): int => time();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar( int $seasonId ): array {
        $ocupaciones = $this->plazaRepository->listOcupacionesVigentesNoTitulares( $seasonId );

        if ( empty( $ocupaciones ) ) {
            return [];
        }

        $plazaIds = array_map( static fn ( array $o ): int => (int) $o['plaza_id'], $ocupaciones );

        // Two batched reads for the whole table, never one per row.
        $cadenas  = $this->plazaRepository->listOcupacionesDePlazas( $plazaIds );
        $abiertas = $this->solicitudesAbiertas->abiertaPorPlazaDeTemporada( $seasonId );

        $cadenaResolver = new CadenaResolver(
            ( new BoundedFechaCounter( $this->fechaRepository, $this->eventLog ) )
                ->boundedCountResolvedFechasSinceFn( $seasonId ),
            $this->settings->minimoFechasCambio()
        );

        $filas         = [];
        $noCalculables = [];

        foreach ( $ocupaciones as $ocupacion ) {
            $plazaId = (int) $ocupacion['plaza_id'];
            $cadena  = $cadenas[ $plazaId ] ?? [];

            [ $faltantes, $indeterminado ] = $this->resolveFaltantes( $cadena, $cadenaResolver );

            if ( $indeterminado ) {
                $noCalculables[] = $plazaId;
            }

            $filas[] = [
                'plaza_id'        => $plazaId,
                'team_id'         => (int) $ocupacion['team_id'],
                'team_nombre'     => $this->nombrePost( (int) $ocupacion['team_id'], __( 'Equipo', 'entre-redes-cambios' ) ),
                'es_arco'         => ! empty( $ocupacion['es_arco'] ),
                'ocupante_id'     => (int) $ocupacion['player_id'],
                'ocupante_nombre' => $this->nombrePost( (int) $ocupacion['player_id'], __( 'Jugador', 'entre-redes-cambios' ) ),
                'titular_id'      => (int) $ocupacion['titular_player_id'],
                'titular_nombre'  => $this->nombrePost( (int) $ocupacion['titular_player_id'], __( 'Jugador', 'entre-redes-cambios' ) ),
                'desde_fecha_id'  => (int) $ocupacion['fecha_desde_id'],
                'faltantes'       => $faltantes,
                'indeterminado'   => $indeterminado,
                // ANY open solicitud, not just a regreso: a pending
                // sustitución speaks for the plaza just as much.
                'solicitud_abierta' => $abiertas[ $plazaId ] ?? null,
            ];
        }

        // ONCE per read, never once per row. The name deliberately does NOT
        // contain `fallid`: `WpEventLog` keeps a single persisted slot and
        // the real record of a broken counter belongs to
        // `BoundedFechaCounter`.
        if ( ! empty( $noCalculables ) ) {
            $this->eventLog->record( 'admin.historico_conteo_no_confiable', [
                'plazas' => $noCalculables,
                'total'  => count( $noCalculables ),
            ] );
        }

        return $filas;
    }

    /**
     * The rows the captains' screen renders, sorted by team name and plaza.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarPublico( int $seasonId ): array {
        $filas = $this->listar( $seasonId );

        if ( empty( $filas ) ) {
            return [];
        }

        // Without the metrics reader every player would read "sin
        // calificar" — a fabricated value on a public list. Fail instead.
        if ( null === $this->metricasReader ) {
            throw new \LogicException( 'CambiosVigentesReader::listarPublico() needs a JugadorMetricasReader.' );
        }

        $playerIds = [];

        foreach ( $filas as $fila ) {
            $playerIds[ (int) $fila['titular_id'] ] = true;
            $playerIds[ (int) $fila['ocupante_id'] ] = true;
        }

        $metricas       = $this->metricasReader->resolveMuchos( array_keys( $playerIds ) );
        $regresoAbierto = $this->regresoTodaviaInformable( $seasonId );

        $etiquetasFecha = [];
        $salida         = [];

        foreach ( $filas as $fila ) {
            $fechaId = (int) $fila['desde_fecha_id'];

            if ( ! array_key_exists( $fechaId, $etiquetasFecha ) ) {
                $etiquetasFecha[ $fechaId ] = $this->etiquetaFecha( $fechaId );
            }

            $situacion = self::situacionPublica( $fila, $regresoAbierto );

            $salida[] = [
                'team_id'          => (int) $fila['team_id'],
                'team_nombre'      => (string) $fila['team_nombre'],
                'plaza_id'         => (int) $fila['plaza_id'],
                'es_arco'          => (bool) $fila['es_arco'],
                'baja'             => $this->jugador( (int) $fila['titular_id'], (string) $fila['titular_nombre'], $metricas ),
                'alta'             => $this->jugador( (int) $fila['ocupante_id'], (string) $fila['ocupante_nombre'], $metricas ),
                'desde'            => [
                    'fecha_id' => $fechaId,
                    'label'    => $etiquetasFecha[ $fechaId ],
                ],
                'situacion'        => $situacion['texto'],
                'situacion_tipo'   => $situacion['tipo'],
                'fechas_restantes' => self::SITUACION_FALTAN_FECHAS === $situacion['tipo'] ? (int) $fila['faltantes'] : null,
            ];
        }

        usort(
            $salida,
            static function ( array $a, array $b ): int {
                $porEquipo = strcasecmp( (string) $a['team_nombre'], (string) $b['team_nombre'] );

                if ( 0 !== $porEquipo ) {
                    return $porEquipo;
                }

                return [ $a['team_id'], $a['plaza_id'] ] <=> [ $b['team_id'], $b['plaza_id'] ];
            }
        );

        return $salida;
    }

    /**
     * The one derived fact this table exists for, as a kind plus its Spanish
     * text. Order matters: an already-filed solicitud is reported as such
     * EVEN IF the minimum is not met yet, because "there is a request on this
     * plaza" is the more actionable fact.
     *
     * @param array<string, mixed> $fila A row from `listar()`.
     * @return array{tipo: string, texto: string}
     */
    public static function situacion( array $fila ): array {
        $abierta = $fila['solicitud_abierta'] ?? null;

        if ( is_array( $abierta ) ) {
            return [
                'tipo'  => self::SITUACION_SOLICITUD_ABIERTA,
                'texto' => sprintf(
                    /* translators: 1: solicitud id, 2: estado */
                    __( 'Solicitud #%1$d abierta (%2$s)', 'entre-redes-cambios' ),
                    (int) $abierta['id'],
                    self::etiquetaEstado( (string) $abierta['estado'] )
                ),
            ];
        }

        if ( true === ( $fila['indeterminado'] ?? false ) ) {
            return [
                'tipo'  => self::SITUACION_INDETERMINADO,
                'texto' => __( 'No se pudo calcular cuánto falta.', 'entre-redes-cambios' ),
            ];
        }

        $faltantes = (int) ( $fila['faltantes'] ?? 0 );

        if ( $faltantes > 0 ) {
            return [
                'tipo'  => self::SITUACION_FALTAN_FECHAS,
                'texto' => sprintf(
                    /* translators: %d: fechas still missing */
                    _n( 'Falta %d fecha para poder volver.', 'Faltan %d fechas para poder volver.', $faltantes, 'entre-redes-cambios' ),
                    $faltantes
                ),
            ];
        }

        return [
            'tipo'  => self::SITUACION_PUEDE_VOLVER,
            'texto' => __( 'El titular ya puede volver — nadie pidió el regreso.', 'entre-redes-cambios' ),
        ];
    }

    /**
     * The situación for the PUBLIC list. Same precedence as situacion() minus
     * its first branch: an open solicitud (pendiente/aprobada, not yet
     * published) is internal, in-flight state of the comisión's workflow —
     * the public list only shows what is already official, so it never says
     * whether a request exists, its id or its estado.
     *
     * Once the minimum is met, the reglamento renews the change one fecha at
     * a time for as long as the captain does not report the titular's
     * return. So the minimum-met state reads two ways, by the week's clock:
     * while the return can still be reported for the next fecha
     * ($regresoAbierto, until `cierre_regresos`), "El titular ya puede
     * volver."; once that deadline passed without a report, "Renovado 1
     * fecha más.".
     *
     * @param array<string, mixed> $fila A row from `listar()`.
     * @return array{tipo: string, texto: string}
     */
    public static function situacionPublica( array $fila, bool $regresoAbierto ): array {
        $sinSolicitud = self::situacion( array_merge( $fila, [ 'solicitud_abierta' => null ] ) );

        if ( self::SITUACION_PUEDE_VOLVER !== $sinSolicitud['tipo'] ) {
            return $sinSolicitud;
        }

        return $regresoAbierto
            ? [
                'tipo'  => self::SITUACION_PUEDE_VOLVER,
                'texto' => __( 'El titular ya puede volver.', 'entre-redes-cambios' ),
            ]
            : [
                'tipo'  => self::SITUACION_RENOVADO,
                'texto' => __( 'Renovado 1 fecha más.', 'entre-redes-cambios' ),
            ];
    }

    /**
     * Whether, right now, a titular's return can still be reported for the
     * next fecha: the season's open fecha (`Calendario\FechaAbierta`) and its
     * `cierre_regresos` plazo from the configured offsets, compared in UTC.
     * With no open fecha left there is nothing to renew into, so the return
     * is reported as still possible.
     */
    private function regresoTodaviaInformable( int $seasonId ): bool {
        $fecha = FechaAbierta::de( $this->fechaRepository, $seasonId );

        if ( null === $fecha ) {
            return true;
        }

        $plazosUtc = PlazosCalculator::computeUtc(
            (string) $fecha['play_date'],
            $this->settings->plazosOffsets(),
            $this->settings->timezone()
        );

        return gmdate( 'Y-m-d H:i:s', ( $this->clockFn )() ) <= $plazosUtc['cierre_regresos'];
    }

    /**
     * Spanish label for ANY `cambios_solicitud.estado`. Total over every
     * estado and never throws: it runs while rendering, after the caller's
     * per-section try/catch has already returned.
     */
    public static function etiquetaEstado( string $estado ): string {
        return match ( $estado ) {
            EstadoSolicitud::PUBLICADA => __( 'Publicada', 'entre-redes-cambios' ),
            EstadoSolicitud::RECHAZADA => __( 'Rechazada', 'entre-redes-cambios' ),
            EstadoSolicitud::ANULADA   => __( 'Anulada', 'entre-redes-cambios' ),
            EstadoSolicitud::PENDIENTE => __( 'Pendiente', 'entre-redes-cambios' ),
            EstadoSolicitud::APROBADA  => __( 'Aprobada', 'entre-redes-cambios' ),
            default                    => __( 'Estado desconocido', 'entre-redes-cambios' ),
        };
    }

    /**
     * Degrades a single unreliable count to "indeterminado" instead of
     * failing the read. An empty chain can only be a race (the row vanished
     * between two reads), so it degrades too.
     *
     * @param array<int, array<string, mixed>> $cadena
     * @return array{0: int|null, 1: bool} [faltantes, indeterminado]
     */
    private function resolveFaltantes( array $cadena, CadenaResolver $cadenaResolver ): array {
        if ( empty( $cadena ) ) {
            return [ null, true ];
        }

        try {
            return [ $cadenaResolver->countFechasUntilLiberacion( $cadena ), false ];
        } catch ( FechaCountUnavailableException $e ) {
            return [ null, true ];
        }
    }

    /**
     * @param array<int, JugadorMetricas> $metricas
     * @return array{player_id: int, nombre: string, puntaje: float|null, sin_calificar: bool}
     */
    private function jugador( int $playerId, string $nombre, array $metricas ): array {
        $puntaje = isset( $metricas[ $playerId ] ) ? $metricas[ $playerId ]->puntaje() : null;

        return [
            'player_id'     => $playerId,
            'nombre'        => $nombre,
            'puntaje'       => null !== $puntaje ? $puntaje->toDecimal() : null,
            'sin_calificar' => null === $puntaje,
        ];
    }

    /**
     * "Apertura Fecha 7" from the calendar row. Falls back to "Fecha {id}"
     * when the row is gone or carries no label, never to a raw empty string.
     */
    private function etiquetaFecha( int $fechaId ): string {
        $fecha = $this->fechaRepository->findById( $fechaId );

        if ( null === $fecha ) {
            return sprintf( __( 'Fecha %d', 'entre-redes-cambios' ), $fechaId );
        }

        $torneo = trim( (string) ( $fecha['torneo_label'] ?? '' ) );
        $numero = (int) ( $fecha['numero_en_torneo'] ?? 0 );

        if ( $numero <= 0 ) {
            return sprintf( __( 'Fecha %d', 'entre-redes-cambios' ), $fechaId );
        }

        return '' !== $torneo
            ? sprintf( '%s Fecha %d', $torneo, $numero )
            : sprintf( __( 'Fecha %d', 'entre-redes-cambios' ), $numero );
    }

    private function nombrePost( int $postId, string $fallbackLabel ): string {
        $titulo = trim( (string) get_the_title( $postId ) );

        return '' !== $titulo ? $titulo : $fallbackLabel . ' #' . $postId;
    }
}
