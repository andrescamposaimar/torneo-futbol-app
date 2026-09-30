<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * Normalizes a free-text name for COMPARISON only — never for display. Every
 * class in this namespace that compares two names (a titular's name in the
 * "GRILLA ELECCION" sheet against the same titular's name in "Titulares
 * eleccion con datos", or an Excel name against a WordPress `sp_player`/`sp_team`
 * `post_title`) normalizes both sides with this SAME function first, so
 * "SINCLAIR , JUAN MARTIN" (a stray space before the comma) and
 * "SINCLAIR, JUAN MARTIN" compare equal, and an accented "María" compares
 * equal to an unaccented "MARIA".
 *
 * Deliberately narrow: uppercase, strip diacritics, keep only `A-Z`, `0-9`,
 * comma and space, collapse repeated whitespace, trim. This is the exact
 * character set `EleccionImporter`'s own analysis (see
 * `docs`/`tools/importar-eleccion.php`'s docblock for how it was derived)
 * proved sufficient to survive every real name shape found in the March 2026
 * election data — nothing here is a general-purpose slugifier.
 */
final class TextNormalizer {

    public static function normalize( string $raw ): string {
        $transliterated = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw );
        $ascii          = false !== $transliterated ? $transliterated : $raw;

        $upper    = strtoupper( $ascii );
        $stripped = preg_replace( '/[^A-Z0-9, ]/', '', $upper ) ?? '';
        $collapsed = preg_replace( '/\s+/', ' ', $stripped ) ?? '';

        return trim( $collapsed );
    }
}
