<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes;

use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Notificaciones\NotificaSolicitudes;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudInvalidaException;
use EntreRedes\Cambios\Solicitudes\Exception\TitularSinPlazaDeCampoException;
use EntreRedes\Cambios\Solicitudes\Lock\CreationLock;

/**
 * THE one creation path for a solicitud de cambio.
 *
 * Both the captain's app (`Rest\SolicitudesController::crear()`) and the
 * commission's "Nueva solicitud" admin page (`Admin\NuevaSolicitudPage`) call
 * this class, so a request loaded on behalf of a captain obeys EXACTLY the
 * same rules as one he files himself. Callers own who the actor is and how
 * the outcome is presented (JWT authorization and HTTP shaping, or the
 * `gestionar_cambios` capability and an admin notice); this class owns
 * everything between "an authorized actor asks for this" and "a `pendiente`
 * row exists":
 *
 *   1. field validation (tipo-dependent required players),
 *   2. the plaza must belong to the given team and season,
 *   3. ONE per-season critical section (`CreationLock`) around the dictamen
 *      evaluation and the insert,
 *   4. the notifications, OUTSIDE that lock.
 *
 * *** THE REFUSAL RULE IS UNCHANGED ***
 * Only the two integrity motivos (`plaza_con_solicitud_abierta`,
 * `entrante_con_solicitud_abierta`) refuse — `SolicitudRepository` throws
 * `SolicitudAbiertaException` before its first insert and this class lets it
 * propagate. Every other motivo stays advisory: the row is stored `pendiente`
 * with its dictamen. See `Rest\SolicitudesController`'s class docblock.
 *
 * *** WHY THE NOTIFICATIONS LIVE HERE, NOT IN THE CALLERS ***
 * The two mails (commission inbox, captain receipt) must follow EVERY
 * creation path, and run strictly after the lock is released (an SMTP stall
 * inside the critical section would answer 503 to every other captain). A
 * single place makes "a path forgot to notify" impossible. The notifier never
 * throws.
 */
class CrearSolicitud {

    private PlazaRepository $plazaRepository;
    private SolicitudRepository $solicitudRepository;
    private DictamenPipeline $dictamenPipeline;
    private ?CreationLock $creationLock;
    private ?NotificaSolicitudes $notifier;

    /**
     * @param CreationLock|null $creationLock Nullable ONLY so a caller used
     *        for something other than creating need not carry one;
     *        `ejecutar()` against an instance wired without it FAILS LOUDLY
     *        (`\LogicException`) instead of creating without serialization.
     * @param NotificaSolicitudes|null $notifier Null means "send nothing".
     */
    public function __construct(
        PlazaRepository $plazaRepository,
        SolicitudRepository $solicitudRepository,
        DictamenPipeline $dictamenPipeline,
        ?CreationLock $creationLock = null,
        ?NotificaSolicitudes $notifier = null
    ) {
        $this->plazaRepository     = $plazaRepository;
        $this->solicitudRepository = $solicitudRepository;
        $this->dictamenPipeline    = $dictamenPipeline;
        $this->creationLock        = $creationLock;
        $this->notifier            = $notifier;
    }

    /**
     * @param int      $ahora           The instant the request is evaluated at,
     *        as a Unix epoch — the caller's single clock read.
     * @param int|null $entrantePlayerId Required for `sustitucion` (the
     *        entrante) and `reasignacion_arquero` (the field titular moving
     *        into goal); ignored for `regreso`.
     * @param int|null $entranteCampoPlayerId Required for
     *        `reasignacion_arquero` alone (the outside player filling the
     *        field plaza the titular vacates); ignored otherwise.
     * @param int|null $creadaPorWpUser The WP user typing the request in on a
     *        captain's behalf (admin page), or null when the captain himself
     *        asked (REST). `$solicitadaPor` is the captain's player id in
     *        both cases.
     * @return array{id: int, dictamen: array{procede: bool, motivos: array<int, array<string, mixed>>, fechas_faltantes_liberacion: int|null}}
     * @throws SolicitudInvalidaException On any input the request cannot be
     *         built from. Nothing was evaluated or stored.
     * @throws Exception\SolicitudAbiertaException A refused duplicate; nothing stored.
     * @throws Exception\CreationLockUnavailableException The season lock could not be taken in time.
     * @throws \Throwable Any other failure (persistence, assembler...).
     */
    public function ejecutar(
        int $seasonId,
        int $teamId,
        int $plazaId,
        int $fechaId,
        string $tipo,
        ?int $entrantePlayerId,
        ?int $entranteCampoPlayerId,
        int $solicitadaPor,
        int $ahora,
        ?int $creadaPorWpUser = null
    ): array {
        $this->validarCampos( $plazaId, $fechaId, $tipo, $entrantePlayerId, $entranteCampoPlayerId );

        // The SAME instant as $ahora, in the other representation the row
        // persists — `solicitada_at` (DATETIME, UTC) and
        // `solicitud_instante_epoch` must describe one moment, never two
        // clock reads.
        $ahoraDb = gmdate( 'Y-m-d H:i:s', $ahora );

        // THE PLAZA MUST BELONG TO THE GIVEN TEAM AND SEASON, FOR EVERY TIPO
        // (for `reasignacion_arquero` this is the goal plaza — the derived
        // field plaza is checked by derivarPlazaCampoId()). Without it a
        // caller could name ANOTHER team's plaza and (a) read whether it has
        // an open request off the refusal — an oracle — and (b) store a
        // `pendiente` row that holds it, locking its real owner out. "Does
        // not exist" and "is not yours" are deliberately indistinguishable,
        // and this runs BEFORE the dictamen so the open-request rules never
        // get to answer about a foreign plaza.
        $plazaSolicitada = $this->plazaRepository->findPlaza( $plazaId );

        if ( null === $plazaSolicitada
            || (int) $plazaSolicitada['season_id'] !== $seasonId
            || (int) $plazaSolicitada['team_id'] !== $teamId
        ) {
            throw new SolicitudInvalidaException(
                'plaza_no_encontrada',
                'La plaza indicada no existe o no pertenece a este equipo y temporada.'
            );
        }

        if ( null === $this->creationLock ) {
            throw new \LogicException(
                'CrearSolicitud::ejecutar(): wired without a CreationLock — refusing to create a solicitud '
                . 'without serializing the open-request check against the insert.'
            );
        }

        // THE WHOLE evaluation + insert runs inside ONE per-season critical
        // section — see Solicitudes\Lock\CreationLock for the race this
        // closes. Everything above needs no lock, so an invalid request never
        // waits for one. The lock is released on every exit path of the
        // closure; if it cannot be acquired in time the closure never runs
        // and CreationLockUnavailableException propagates.
        $resultado = $this->creationLock->run( $seasonId, function () use (
            $tipo, $seasonId, $teamId, $plazaId, $entrantePlayerId, $entranteCampoPlayerId, $fechaId, $ahora, $solicitadaPor, $ahoraDb, $creadaPorWpUser
        ): array {
            if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
                $titularPlayerId = (int) $entrantePlayerId;

                $plazaCampoId = $this->derivarPlazaCampoId( $seasonId, $teamId, $plazaId, $titularPlayerId );

                $id = $this->solicitudRepository->crearReasignacionArquero(
                    $seasonId,
                    $teamId,
                    $plazaId,
                    $titularPlayerId,
                    $plazaCampoId,
                    (int) $entranteCampoPlayerId,
                    $fechaId,
                    $ahora,
                    $solicitadaPor,
                    $ahoraDb,
                    $creadaPorWpUser
                );

                $row = $this->solicitudRepository->findSolicitud( $id );

                if ( null === $row ) {
                    throw new \RuntimeException( "CrearSolicitud::ejecutar(): solicitud {$id} not found immediately after creation." );
                }

                // This tipo never hands back a live Dictamen — the repository
                // evaluates evaluateGrupo() internally. The outcome is rebuilt
                // from the SAME persisted verdict, never from a second,
                // redundant evaluation that could disagree with what was stored.
                $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );

                return [ 'id' => $id, 'dictamen' => $this->shapeDictamenDesdeSnapshot( $snapshot ) ];
            }

            $solicitud = SolicitudDeCambio::TIPO_SUSTITUCION === $tipo
                ? SolicitudDeCambio::sustitucion( $seasonId, $teamId, $plazaId, (int) $entrantePlayerId, $fechaId, $ahora )
                : SolicitudDeCambio::regreso( $seasonId, $teamId, $plazaId, $fechaId, $ahora );

            $dictamen = $this->dictamenPipeline->evaluate( $solicitud );

            $id = $this->solicitudRepository->crear( $solicitud, $solicitadaPor, $dictamen, $ahoraDb, $creadaPorWpUser );

            return [ 'id' => $id, 'dictamen' => $this->shapeDictamen( $dictamen ) ];
        } );

        // *** OUTSIDE THE LOCK, ON PURPOSE *** run() has returned, so the
        // per-season GET_LOCK is already released. wp_mail() can block for
        // seconds on an SMTP timeout; SolicitudNotifierTest pins this as a
        // property — it is invisible from here. Two readers, two mails: the
        // comisión gets told there is work, the captain gets a receipt. Each
        // is gated by its own setting and neither can throw.
        if ( null !== $this->notifier ) {
            $this->notifier->notificarCreada( $resultado['id'] );
            $this->notifier->notificarCreadaAlCapitan( $resultado['id'] );
        }

        return $resultado;
    }

    /**
     * Same checks, same codes, same messages, same ORDER as the REST route
     * always had — a client keys on `codigo`.
     *
     * @throws SolicitudInvalidaException
     */
    private function validarCampos(
        int $plazaId,
        int $fechaId,
        string $tipo,
        ?int $entrantePlayerId,
        ?int $entranteCampoPlayerId
    ): void {
        if ( $plazaId <= 0 || $fechaId <= 0 ) {
            throw new SolicitudInvalidaException(
                'campos_invalidos',
                'plaza_id y fecha_id son obligatorios y deben ser mayores a 0.'
            );
        }

        if ( ! in_array(
            $tipo,
            [ SolicitudDeCambio::TIPO_SUSTITUCION, SolicitudDeCambio::TIPO_REGRESO, SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO ],
            true
        ) ) {
            throw new SolicitudInvalidaException(
                'tipo_invalido',
                "tipo debe ser 'sustitucion', 'regreso' o 'reasignacion_arquero'."
            );
        }

        if ( SolicitudDeCambio::TIPO_SUSTITUCION === $tipo && ( null === $entrantePlayerId || $entrantePlayerId <= 0 ) ) {
            throw new SolicitudInvalidaException(
                'entrante_requerido',
                "entrante_player_id es obligatorio para tipo 'sustitucion'."
            );
        }

        if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
            if ( null === $entrantePlayerId || $entrantePlayerId <= 0 ) {
                throw new SolicitudInvalidaException(
                    'titular_requerido',
                    "entrante_player_id (el titular de campo que pasa al arco) es obligatorio para tipo 'reasignacion_arquero'."
                );
            }

            if ( null === $entranteCampoPlayerId || $entranteCampoPlayerId <= 0 ) {
                throw new SolicitudInvalidaException(
                    'entrante_campo_requerido',
                    "entrante_campo_player_id es obligatorio para tipo 'reasignacion_arquero'."
                );
            }
        }
    }

    /** @return array{procede: bool, motivos: array<int, array{codigo: string, mensaje: string, datos: array<string, mixed>}>, fechas_faltantes_liberacion: int|null} */
    private function shapeDictamen( Dictamen $dictamen ): array {
        return [
            'procede' => $dictamen->procede(),
            'motivos' => array_map(
                static fn ( Motivo $motivo ): array => [
                    'codigo'  => $motivo->codigo(),
                    'mensaje' => $motivo->mensaje(),
                    'datos'   => $motivo->datos(),
                ],
                $dictamen->motivos()
            ),
            'fechas_faltantes_liberacion' => $dictamen->fechasFaltantesParaLiberacion(),
        ];
    }

    /**
     * Same shape as shapeDictamen(), rebuilt from a persisted snapshot.
     * `fechas_faltantes_liberacion` is recomputed with the EXACT algorithm
     * `Dictamen::fechasFaltantesParaLiberacion()` applies — scan every motivo
     * for a `fechasFaltantes` key in its `datos` — since `DictamenSnapshot`
     * is a plain `{procede, motivos, evaluado_at}` triple with no derived
     * accessors.
     *
     * @return array{procede: bool, motivos: array<int, array<string, mixed>>, fechas_faltantes_liberacion: int|null}
     */
    private function shapeDictamenDesdeSnapshot( DictamenSnapshot $snapshot ): array {
        $fechasFaltantes = null;

        foreach ( $snapshot->motivos() as $motivo ) {
            $datos = $motivo['datos'] ?? [];

            if ( is_array( $datos ) && array_key_exists( 'fechasFaltantes', $datos ) ) {
                $fechasFaltantes = null === $datos['fechasFaltantes'] ? null : (int) $datos['fechasFaltantes'];
                break;
            }
        }

        return [
            'procede'                     => $snapshot->procede(),
            'motivos'                     => $snapshot->motivos(),
            'fechas_faltantes_liberacion' => $fechasFaltantes,
        ];
    }

    /**
     * Derives the field plaza a `reasignacion_arquero` request's titular
     * vacates, from the titular's OWN current vigent occupation — never from
     * a caller-supplied value.
     *
     * `SolicitudRepository::assertPlazasDeReasignacionArquero()` only checks
     * that whatever `plaza_campo_id` it is HANDED belongs to the right
     * team/season and is not the goal plaza — it cannot know whether that is
     * the plaza THIS titular actually occupies. Deriving it from
     * `PlazaRepository::listOcupacionesVigentesDeJugador()` makes the
     * disagreement structurally impossible. `$plazaArcoId` is passed as
     * `$excluyendoPlazaId` purely for symmetry with every other caller.
     *
     * @throws \RuntimeException When $titularPlayerId does not hold EXACTLY
     *         ONE vigent field-plaza (`es_arco = 0`) occupation within
     *         $teamId/$seasonId — a structural precondition of this tipo,
     *         not a reglamento objection a Regla should report as a Motivo.
     */
    private function derivarPlazaCampoId( int $seasonId, int $teamId, int $plazaArcoId, int $titularPlayerId ): int {
        $vigentes = $this->plazaRepository->listOcupacionesVigentesDeJugador( $seasonId, $titularPlayerId, $plazaArcoId );

        $plazasDeCampoDelEquipo = [];

        foreach ( $vigentes as $ocupacion ) {
            $plaza = $this->plazaRepository->findPlaza( (int) $ocupacion['plaza_id'] );

            if ( null !== $plaza && (int) $plaza['team_id'] === $teamId && ! (bool) ( $plaza['es_arco'] ?? false ) ) {
                $plazasDeCampoDelEquipo[] = (int) $ocupacion['plaza_id'];
            }
        }

        $plazasDeCampoDelEquipo = array_values( array_unique( $plazasDeCampoDelEquipo ) );

        if ( 1 !== count( $plazasDeCampoDelEquipo ) ) {
            throw new TitularSinPlazaDeCampoException(
                "CrearSolicitud::derivarPlazaCampoId(): titular_player_id {$titularPlayerId} does not hold exactly one vigent field plaza in team_id {$teamId}/season_id {$seasonId} (found " . count( $plazasDeCampoDelEquipo ) . ').',
                $titularPlayerId
            );
        }

        return $plazasDeCampoDelEquipo[0];
    }
}
