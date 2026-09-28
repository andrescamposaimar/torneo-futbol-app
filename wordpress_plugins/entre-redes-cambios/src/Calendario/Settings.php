<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

use EntreRedes\Cambios\Migrations\InitialSchema;

/**
 * Typed accessor for Cambios operator settings stored in cambios_settings.
 *
 * Reads the key/value table for each getter and falls back to
 * InitialSchema::SEED_DEFAULTS when the row is absent — never a separate
 * hardcoded literal, so the fallback and the seed can never silently drift
 * apart (mirrors entre-redes-prode's Fecha\Settings).
 */
class Settings {

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * IANA timezone identifier used to compute civil plazos.
     * Default: 'America/Argentina/Buenos_Aires'.
     */
    public function timezone(): string {
        return $this->readString( 'timezone', (string) InitialSchema::SEED_DEFAULTS['timezone'] );
    }

    /**
     * The SportsPress season term id the calendar is seeded for.
     * Default: 359.
     */
    public function seasonId(): int {
        return $this->readInt( 'season_id', (int) InitialSchema::SEED_DEFAULTS['season_id'] );
    }

    /** @return array{days:int, time:string} */
    public function aperturaSolicitudesOffset(): array {
        return $this->readOffset( 'plazo_apertura_solicitudes' );
    }

    /** @return array{days:int, time:string} */
    public function cierreRegresosOffset(): array {
        return $this->readOffset( 'plazo_cierre_regresos' );
    }

    /** @return array{days:int, time:string} */
    public function cierreSolicitudesOffset(): array {
        return $this->readOffset( 'plazo_cierre_solicitudes' );
    }

    /** @return array{days:int, time:string} */
    public function publicacionOffset(): array {
        return $this->readOffset( 'plazo_publicacion' );
    }

    /**
     * All four offsets, keyed exactly as PlazosCalculator::compute() expects.
     *
     * @return array<string, array{days:int, time:string}>
     */
    public function plazosOffsets(): array {
        return [
            'apertura_solicitudes' => $this->aperturaSolicitudesOffset(),
            'cierre_regresos'      => $this->cierreRegresosOffset(),
            'cierre_solicitudes'   => $this->cierreSolicitudesOffset(),
            'publicacion'          => $this->publicacionOffset(),
        ];
    }

    /**
     * Whether the reglamento's "prioridad para padres" is an ENFORCED gate
     * (a non-padre entrante is ineligible whenever a viable padre exists for
     * the plaza) rather than the soft, unenforced preference it is today —
     * see Dictamen\Reglas\PrioridadDePadresRespetada's own docblock for the
     * full rule. Default: `false` (OFF) — see
     * Migrations\InitialSchema::SEED_DEFAULTS, 'prioridad_padres_activa'.
     */
    public function prioridadPadresActiva(): bool {
        return $this->readBool( 'prioridad_padres_activa', (string) InitialSchema::SEED_DEFAULTS['prioridad_padres_activa'] );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array{days:int, time:string} */
    private function readOffset( string $key ): array {
        $defaultJson = (string) InitialSchema::SEED_DEFAULTS[ $key ];
        $raw         = $this->readString( $key, $defaultJson );

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['days'], $decoded['time'] ) ) {
            $decoded = json_decode( $defaultJson, true );
        }

        return [
            'days' => (int) $decoded['days'],
            'time' => (string) $decoded['time'],
        ];
    }

    private function readString( string $key, string $default ): string {
        $p     = $this->wpdb->prefix;
        $value = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT setting_value FROM {$p}cambios_settings WHERE setting_key = %s",
                $key
            )
        );

        return null === $value ? $default : (string) $value;
    }

    private function readInt( string $key, int $default ): int {
        return (int) $this->readString( $key, (string) $default );
    }

    /**
     * `'1'` is true, anything else (including an absent row, via $default)
     * is false — the same "stored as a string, typed at the accessor"
     * discipline as readInt(), never a second storage representation.
     */
    private function readBool( string $key, string $default ): bool {
        return '1' === $this->readString( $key, $default );
    }
}
