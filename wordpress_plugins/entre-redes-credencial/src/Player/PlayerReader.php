<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Player;

/**
 * Read model for player-credential eligibility (design D14, spec "Eligibility
 * Determination" / "Credential Payload and Display").
 *
 * Reads a `sp_player` post plus THREE plain postmeta keys — `estado`, `dni`,
 * `caracter` — directly via get_post()/get_post_meta(), never through an ACF
 * field key (design P2: the caracter/estado normalization already ran in
 * production; this class only ever reads the plain, normalized value).
 *
 * `$now` is injected on every call, never read from the system clock here —
 * it decides which side of the [18, 100] year birth-date window the player's
 * `post_date` falls on (spec: age is neither validated nor blocking; an
 * out-of-range birth date is simply omitted, not an error).
 */
final class PlayerReader {

    /** Spec precondition: the 5 valid `caracter` values (exact match only). */
    private const VALID_CARACTERES = [
        'Padre Alumno',
        'Padre Ex-Alumno',
        'Invitado',
        'Personal Colegio',
        'Socio Fundador',
    ];

    private const MIN_AGE = 18;
    private const MAX_AGE = 100;

    public function resolve( int $playerId, int $now ): ?PlayerRecord {
        $post = get_post( $playerId );

        if ( null === $post || 'sp_player' !== $post->post_type ) {
            return null;
        }

        $estado   = (string) get_post_meta( $playerId, 'estado', true );
        $dni      = (string) get_post_meta( $playerId, 'dni', true );
        $caracter = (string) get_post_meta( $playerId, 'caracter', true );

        return new PlayerRecord(
            $playerId,
            (string) $post->post_title,
            $dni,
            self::caracterOrNull( $caracter ),
            self::birthDateOrNull( (string) $post->post_date, $now ),
            self::isInhabilitado( $estado )
        );
    }

    private static function isInhabilitado( string $estado ): bool {
        return 'inhabilitado' === strtolower( trim( $estado ) );
    }

    private static function caracterOrNull( string $caracter ): ?string {
        return in_array( $caracter, self::VALID_CARACTERES, true ) ? $caracter : null;
    }

    private static function birthDateOrNull( string $postDate, int $now ): ?string {
        if ( '' === $postDate || str_starts_with( $postDate, '0000-00-00' ) ) {
            return null;
        }

        try {
            $birth = new \DateTimeImmutable( $postDate, new \DateTimeZone( 'UTC' ) );
        } catch ( \Exception $e ) {
            return null;
        }

        $nowDt = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
        $age   = $birth->diff( $nowDt )->y;

        if ( $age < self::MIN_AGE || $age > self::MAX_AGE ) {
            return null;
        }

        return $birth->format( 'Y-m-d' );
    }
}
