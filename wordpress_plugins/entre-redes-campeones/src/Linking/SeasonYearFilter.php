<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * sp_season term names are ALWAYS a bare 4-digit year (design §3, verified
 * against wordpress_sql/entrered_wp257.sql). The anchored-leading-year rule
 * below is defensive, not measured: every observed value already passes
 * plain equality. Rejected: str_contains($name, (string) $year) — a
 * compound sp_league name like "2022 - Apertura Zona A" or "Copa
 * 2016-2017" would satisfy more than one year.
 */
final class SeasonYearFilter {

    public static function seasonCoversYear( string $seasonName, int $year ): bool {
        if ( 1 !== preg_match( '/^\s*(\d{4})/', $seasonName, $matches ) ) {
            return false;
        }

        return (int) $matches[1] === $year;
    }
}
