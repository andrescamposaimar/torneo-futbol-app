<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * Normalizes a name PART for matching (design §3).
 *
 * Applied to name parts, never to a whole "APELLIDO, X." string — NameParser
 * owns the comma and splits first.
 *
 * Deliberately NOT iconv('UTF-8', 'ASCII//TRANSLIT') (locale/libc dependent —
 * the same input can yield different output on different hosts) and NOT
 * Normalizer::normalize() (requires ext-intl, unavailable on the shared host
 * and absent from the test shim). An explicit strtr() map is the only
 * approach whose output is identical everywhere this code runs.
 */
final class NameNormalizer {

    /**
     * @var array<string, string>
     */
    private const ACCENT_MAP = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        'Ñ' => 'N', 'Ç' => 'C', 'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O',
        'Ù' => 'U', 'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
        'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O',
        // Defensive: lowercase equivalents, in case a value reaches this
        // function before uppercasing for any reason.
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o',
        'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
    ];

    public static function normalize( string $raw ): string {
        $value = trim( $raw );
        $value = mb_strtoupper( $value, 'UTF-8' );
        $value = strtr( $value, self::ACCENT_MAP );
        $value = preg_replace( '/[^A-Z0-9 ]/', ' ', $value ) ?? '';
        $value = preg_replace( '/\s+/', ' ', $value ) ?? '';

        return trim( $value );
    }
}
