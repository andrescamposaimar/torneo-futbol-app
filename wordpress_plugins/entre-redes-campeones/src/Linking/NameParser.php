<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * Derives exactly one PlayerKey per name, or null for a name that cannot
 * produce a valid surname (design §3, ADR-C1).
 *
 * Read ADR-C1 before changing anything in this class. Four adversarial
 * review rounds each found a new defect in a generated-surname-variant
 * predecessor of this class; this conservative version replaced it
 * entirely. The invariant that makes it safe and checkable by reading:
 *
 *     initial === '' if and only if the name reduces to exactly one token.
 *
 * One method, used for BOTH the spreadsheet entry and the registered
 * player's post_title — there is no separate "fromSpreadsheet" /
 * "fromRegisteredPlayer" pair. Two methods would be two rules, and two
 * rules is a place for the sides to disagree; they cannot disagree if
 * there is only one.
 */
final class NameParser {

    /**
     * @var string[]
     */
    private const PARTICLES = [
        'DE', 'DEL', 'EL', 'LA', 'LAS', 'LOS', 'VAN', 'VON', 'DA', 'DI', 'DOS',
    ];

    /**
     * The single source of truth for the particle list — exposed so that
     * NameParserPropertyTest (the mandatory guard over the real 1105-title
     * corpus) consumes the actual rule instead of hand-copying it. A private
     * constant plus a hand-copied test list can silently drift; this cannot.
     *
     * @return string[]
     */
    public static function particles(): array {
        return self::PARTICLES;
    }

    public static function keyFor( string $raw ): ?PlayerKey {
        $commaPos = strpos( $raw, ',' );

        if ( false !== $commaPos ) {
            $rightNormalized = NameNormalizer::normalize( substr( $raw, $commaPos + 1 ) );

            if ( '' !== $rightNormalized ) {
                $surname = NameNormalizer::normalize( substr( $raw, 0, $commaPos ) );
                $initial = mb_substr( $rightNormalized, 0, 1, 'UTF-8' );

                return self::validate( new PlayerKey( $surname, $initial ) );
            }
        }

        $prepassed  = self::stripMiddleInitialPeriods( $raw );
        $normalized = NameNormalizer::normalize( $prepassed );
        $tokens     = '' === $normalized ? [] : explode( ' ', $normalized );
        $count      = count( $tokens );

        if ( 0 === $count ) {
            return null;
        }

        if ( 1 === $count ) {
            return self::validate( new PlayerKey( $tokens[0], '' ) );
        }

        if ( 2 === $count && in_array( $tokens[0], self::PARTICLES, true ) ) {
            return null;
        }

        $i = $count - 1;
        while ( $i > 1 && self::isAbsorbable( $tokens[ $i - 1 ] ) ) {
            $i--;
        }

        $surname = implode( ' ', array_slice( $tokens, $i ) );
        $initial = mb_substr( $tokens[0], 0, 1, 'UTF-8' );

        return self::validate( new PlayerKey( $surname, $initial ) );
    }

    /**
     * Removes any RAW token matching a single letter followed by '.' (e.g.
     * "A."), except the FIRST token. Must run on the raw string BEFORE
     * normalize() turns both '.' and apostrophe-like marks into spaces —
     * after that point a middle initial and an apostrophe fragment are
     * indistinguishable.
     */
    private static function stripMiddleInitialPeriods( string $raw ): string {
        $tokens = preg_split( '/\s+/', trim( $raw ) );
        if ( false === $tokens ) {
            return trim( $raw );
        }

        foreach ( $tokens as $index => $token ) {
            if ( 0 === $index ) {
                continue;
            }
            if ( 1 === preg_match( '/^[A-Za-z]\.$/', $token ) ) {
                unset( $tokens[ $index ] );
            }
        }

        return implode( ' ', $tokens );
    }

    private static function isAbsorbable( string $token ): bool {
        return in_array( $token, self::PARTICLES, true ) || 1 === mb_strlen( $token, 'UTF-8' );
    }

    private static function validate( PlayerKey $key ): ?PlayerKey {
        if ( mb_strlen( $key->surname, 'UTF-8' ) < 2 ) {
            return null;
        }

        foreach ( explode( ' ', $key->surname ) as $token ) {
            if ( ! in_array( $token, self::PARTICLES, true ) ) {
                return $key;
            }
        }

        // Every token of the surname is a particle (e.g. "DE LOS") — reject.
        return null;
    }
}
