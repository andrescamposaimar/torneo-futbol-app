<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Rest;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Approval\Exception\AlreadyPendingException;
use EntreRedes\Credencial\Approval\Exception\ApprovalPersistenceException;
use EntreRedes\Credencial\Auth\CredencialAuthorizer;
use EntreRedes\Credencial\Observability\EventLog;
use EntreRedes\Credencial\Photo\Exception\EmptyBodyException;
use EntreRedes\Credencial\Photo\Exception\ImageDimensionsException;
use EntreRedes\Credencial\Photo\Exception\ImageTooLargeException;
use EntreRedes\Credencial\Photo\Exception\InsufficientMemoryException;
use EntreRedes\Credencial\Photo\Exception\InvalidBase64Exception;
use EntreRedes\Credencial\Photo\Exception\InvalidImageException;
use EntreRedes\Credencial\Photo\Exception\ReencodeFailedException;
use EntreRedes\Credencial\Photo\MemoryGuard;
use EntreRedes\Credencial\Photo\PhotoReencoder;
use EntreRedes\Credencial\Photo\PhotoValidator;
use EntreRedes\Credencial\Photo\UploadBodyReader;
use EntreRedes\Credencial\Player\PlayerReader;

/**
 * POST /entre-redes/v1/credencial/foto — design D7 (`{image_base64}` JSON
 * body), D9 (the full validation pipeline and its error codes) and D10 (one
 * pending request per player, DB-enforced).
 *
 * *** AUTHORIZATION RUNS FIRST, ALWAYS *** — same discipline as
 * CredencialController. `HandlesCredencialAuthorization` (shared with that
 * controller) owns the WP_Error passthrough and the generic 500 body.
 *
 * The pipeline runs in a fixed order, each stage able to reject the request
 * on its own terms before the next one ever runs — eligibility before rate
 * limiting before decoding before validating before the (comparatively
 * expensive) memory check and re-encode, so a request that will be rejected
 * anyway never pays for the stages after the one that rejects it:
 *   auth -> eligibility (not_a_player/blocked) -> rate limit -> body decode
 *   -> dimension validation -> memory guard -> re-encode -> persist.
 */
final class PhotoUploadController {
    use HandlesCredencialAuthorization;

    /** Design D9: "5 uploads/24h (429)". */
    private const MAX_UPLOADS_PER_WINDOW    = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 24 * 60 * 60;

    private CredencialAuthorizer $authorizer;
    private PlayerReader $playerReader;
    private UploadBodyReader $bodyReader;
    private PhotoValidator $validator;
    private MemoryGuard $memoryGuard;
    private PhotoReencoder $reencoder;
    private ApprovalRequestRepository $approvalRequestRepository;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /** @var callable(): string */
    private $memoryLimitFn;

    /** @var callable(): int */
    private $memoryUsageFn;

    /**
     * @param callable(): int|null    $clockFn       Defaults to the real clock — see
     *        CredencialController's own constructor docblock for why this is injectable.
     * @param callable(): string|null $memoryLimitFn Forwarded to MemoryGuard::ensureEnoughMemoryFor() —
     *        defaults to MemoryGuard's own real `ini_get('memory_limit')` reader.
     * @param callable(): int|null    $memoryUsageFn Forwarded to MemoryGuard::ensureEnoughMemoryFor() —
     *        defaults to MemoryGuard's own real `memory_get_usage(true)` reader.
     */
    public function __construct(
        CredencialAuthorizer $authorizer,
        PlayerReader $playerReader,
        UploadBodyReader $bodyReader,
        PhotoValidator $validator,
        MemoryGuard $memoryGuard,
        PhotoReencoder $reencoder,
        ApprovalRequestRepository $approvalRequestRepository,
        EventLog $eventLog,
        ?callable $clockFn = null,
        ?callable $memoryLimitFn = null,
        ?callable $memoryUsageFn = null
    ) {
        $this->authorizer                = $authorizer;
        $this->playerReader              = $playerReader;
        $this->bodyReader                = $bodyReader;
        $this->validator                 = $validator;
        $this->memoryGuard               = $memoryGuard;
        $this->reencoder                 = $reencoder;
        $this->approvalRequestRepository = $approvalRequestRepository;
        $this->eventLog                  = $eventLog;
        $this->clockFn                   = $clockFn ?? static fn (): int => time();
        $this->memoryLimitFn             = $memoryLimitFn;
        $this->memoryUsageFn             = $memoryUsageFn;
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/foto',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'upload' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Response 202: `{request_id, status: pending}` — design Interfaces section.
     * Response 400/413/422/500/409/403/429: see this class's own docblock and
     * design D9's error list.
     * Response 401: passthrough from CredencialAuthorizer.
     */
    public function upload( \WP_REST_Request $request ): \WP_REST_Response {
        $now = ( $this->clockFn )();

        $authResult = $this->authorizer->authorize(
            (string) ( $request->get_header( 'authorization' ) ?? '' ),
            $now
        );

        if ( $authResult instanceof \WP_Error ) {
            return self::respuestaDesdeWpError( $authResult );
        }

        $playerId = (int) $authResult['player_id'];
        $userId   = (int) $authResult['user_id'];

        $player = $this->playerReader->resolve( $playerId, $now );

        if ( null === $player ) {
            return self::errorResponse( 'not_a_player', 'No existe un jugador asociado a esta cuenta.', 403 );
        }

        if ( $player->isBlocked() ) {
            return self::errorResponse( 'blocked', 'Tu acceso está inhabilitado.', 403 );
        }

        $recentUploads = $this->approvalRequestRepository->countRequestsSince(
            $playerId,
            ApprovalRequestRepository::TYPE_PHOTO,
            $now - self::RATE_LIMIT_WINDOW_SECONDS
        );

        if ( $recentUploads >= self::MAX_UPLOADS_PER_WINDOW ) {
            return self::errorResponse(
                'too_many_uploads',
                'Superaste el límite de subidas permitidas. Probá de nuevo más tarde.',
                429
            );
        }

        try {
            $decodedBytes = $this->bodyReader->read( $request->get_param( 'image_base64' ) );
        } catch ( EmptyBodyException $e ) {
            return self::errorResponse( 'empty_body', 'No se recibió ninguna imagen.', 400 );
        } catch ( InvalidBase64Exception $e ) {
            return self::errorResponse( 'invalid_base64', 'La imagen enviada no es un base64 válido.', 400 );
        } catch ( ImageTooLargeException $e ) {
            return self::errorResponse( 'image_too_large', 'La imagen supera el tamaño máximo permitido.', 413 );
        }

        try {
            $info = $this->validator->validate( $decodedBytes );
        } catch ( InvalidImageException $e ) {
            return self::errorResponse( 'invalid_image', 'El archivo enviado no es una imagen JPEG o PNG válida.', 400 );
        } catch ( ImageDimensionsException $e ) {
            return self::errorResponse( 'image_dimensions', 'Las dimensiones de la imagen están fuera de lo permitido.', 422 );
        }

        try {
            $this->memoryGuard->ensureEnoughMemoryFor( $info['width'], $info['height'], $this->memoryLimitFn, $this->memoryUsageFn );
        } catch ( InsufficientMemoryException $e ) {
            $this->eventLog->record( 'photo.insufficient_memory', [
                'player_id' => $playerId,
                'width'     => $info['width'],
                'height'    => $info['height'],
            ] );

            return self::errorResponse(
                'insufficient_memory',
                'No se pudo procesar la imagen en este momento. Probá de nuevo en unos minutos.',
                500
            );
        }

        try {
            $reencodedBytes = $this->reencoder->reencode( $decodedBytes );
        } catch ( ReencodeFailedException $e ) {
            return self::errorResponse( 'invalid_image', 'El archivo enviado no es una imagen válida.', 400 );
        }

        try {
            $requestId = $this->approvalRequestRepository->createPendingPhotoRequest( $playerId, $userId, $reencodedBytes, $now );
        } catch ( AlreadyPendingException $e ) {
            return self::errorResponse(
                'already_pending',
                'Ya tenés una foto pendiente de revisión.',
                409
            );
        } catch ( ApprovalPersistenceException $e ) {
            $this->eventLog->record( 'rest.photo_upload_failed', [
                'player_id' => $playerId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return self::respuestaErrorInterno();
        }

        return new \WP_REST_Response( [ 'request_id' => $requestId, 'status' => 'pending' ], 202 );
    }

    private static function errorResponse( string $code, string $message, int $status ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'code'    => $code,
                'message' => $message,
                'data'    => [ 'status' => $status ],
            ],
            $status
        );
    }
}
