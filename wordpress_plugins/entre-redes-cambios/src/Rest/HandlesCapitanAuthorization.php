<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Capitania\CapitanAuthorizer;

/**
 * Shared authorization + error-envelope plumbing for every captain-facing
 * REST endpoint in this plugin (Rest\SolicitudesController, Rest\PlazasController).
 *
 * *** WHY THIS IS A TRAIT, NOT A BASE CLASS ***
 * Both controllers already need their own distinct constructor dependencies
 * (SolicitudRepository + DictamenPipeline vs PlazaRepository + FechaRepository)
 * — a trait composes with that without forcing an artificial common parent,
 * the same reason PlazaRepository / SolicitudRepository / CapitanRepository
 * all reuse Support\OpensTransactions as a trait instead of a base class.
 *
 * *** THE GENERIC-ERROR DISCIPLINE THIS TRAIT ENFORCES ***
 * `respuestaNoAutorizada()` and `respuestaErrorInterno()` are the ONLY two
 * response bodies every captain endpoint may return for, respectively, an
 * authorization failure and an unexpected engine failure — neither ever
 * includes an exception's message, class name, or trace (see this slice's
 * task brief: "la respuesta de error nunca incluye el mensaje de la
 * excepcion, ni trazas, ni nombres de clase. Un codigo estable y un texto en
 * espanol para el capitan."). The real reason is always the caller's job to
 * log via EventLog BEFORE building either response — this trait only builds
 * the body, it never logs, so every call site logs its OWN identifiers
 * (season/team/plaza/fecha) rather than a fixed subset this trait would have
 * to guess at.
 *
 * A dictamen that does not `procede()` is NEVER represented by either of
 * these two responses — see Dictamen\Dictamen's own docblock, "a dictamen is
 * not an approval": that is a normal 200, decided entirely by the
 * controller, not this trait.
 */
trait HandlesCapitanAuthorization {

    /**
     * Extracts the bearer token from the Authorization header. Returns an
     * empty string when the header is absent or does not carry a Bearer
     * scheme — CapitanAuthorizer::authorize() then rejects that exactly like
     * any other malformed token (Exception\InvalidTokenException), through
     * the SAME code path a bad signature would take. No special-casing
     * "missing header" as its own failure keeps the "same generic response
     * for every authorization failure" contract in ONE place
     * (Capitania\Exception\AuthorizationDeniedException), never duplicated
     * here.
     */
    private function extractBearerToken( \WP_REST_Request $request ): string {
        $header = (string) ( $request->get_header( 'authorization' ) ?? '' );

        if ( ! str_starts_with( $header, 'Bearer ' ) ) {
            return '';
        }

        return substr( $header, strlen( 'Bearer ' ) );
    }

    /**
     * Runs CapitanAuthorizer::authorize() with the request's own bearer
     * token — see extractBearerToken() — and the caller-supplied `$now` as
     * the current instant.
     *
     * *** WHY $now IS A PARAMETER, NEVER time() CALLED IN HERE ***
     * CapitanAuthorizer::authorize()'s `$nowTimestamp` is a Unix epoch
     * exactly like TokenVerifier::verify()'s own `$now` — see that method's
     * docblock for the incident this guards against: `current_time('mysql')`
     * hands out a LOCAL civil string that TokenVerifier would then have to
     * guess a timezone for, silently shifting "now" and misjudging `exp`. A
     * plain Unix epoch has no timezone to misread, which is why every
     * caller must supply one — but this trait itself must not be the one
     * calling `time()`: each controller owns an injectable `$clockFn` (see
     * SolicitudesController / PlazasController constructors) precisely so
     * a test can freeze "now" instead of depending on the real clock. If
     * this method called `time()` directly, that injection would be
     * pointless — the authorization check would still read the real clock
     * regardless of what the controller's own `$clockFn` returned.
     *
     * This method NEVER decides the HTTP response itself — that is each
     * controller's own catch block, so it can log its OWN identifiers
     * (plaza_id, fecha_id, etc., where applicable) alongside season/team.
     *
     * @param int $now Unix epoch, from the calling controller's `$clockFn`.
     * @return array<string, mixed> The verified JWT claims.
     * @throws \EntreRedes\Cambios\Capitania\Exception\AuthorizationDeniedException
     *         Same three types CapitanAuthorizer::authorize() throws —
     *         propagated as-is.
     */
    private function authorizeCapitan(
        CapitanAuthorizer $authorizer,
        \WP_REST_Request $request,
        int $seasonId,
        int $teamId,
        int $now
    ): array {
        return $authorizer->authorize( $this->extractBearerToken( $request ), $seasonId, $teamId, $now );
    }

    /**
     * THE single 403 body every captain endpoint returns for EVERY
     * authorization failure — see class docblock: the caller-facing text
     * must never reveal which of the three AuthorizationDeniedException
     * subtypes fired (invalid token, revoked session, or not captain of
     * THIS team).
     */
    private function respuestaNoAutorizada(): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'code'    => 'no_autorizado',
                'message' => 'No estás autorizado para realizar esta acción en este equipo y temporada.',
                'data'    => [ 'status' => 403 ],
            ],
            403
        );
    }

    /**
     * THE single 500 body every captain endpoint returns for an UNEXPECTED
     * failure (anything caught as \Throwable that is not an
     * AuthorizationDeniedException) — see class docblock.
     */
    private function respuestaErrorInterno(): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'code'    => 'error_interno',
                'message' => 'Ocurrió un error al procesar la solicitud. Probá de nuevo en unos minutos.',
                'data'    => [ 'status' => 500 ],
            ],
            500
        );
    }

    /**
     * A plain 400 for a caller-side request-shape problem (a missing or
     * malformed field) — distinct from both of the above: this is NEITHER an
     * authorization failure NOR an unexpected engine failure, so it is safe
     * to say exactly what is wrong.
     */
    private function respuestaSolicitudInvalida( string $code, string $message ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'code'    => $code,
                'message' => $message,
                'data'    => [ 'status' => 400 ],
            ],
            400
        );
    }
}
