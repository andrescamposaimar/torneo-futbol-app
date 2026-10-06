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

    /**
     * Whether "la exención del arco" is ON — the goal plaza's techo does not
     * apply to the field titular moving into it as movement 1 of a grouped
     * goalkeeper reassignment (see `Dictamen\Reglas\PuntajeDentroDelTecho`
     * and `Dictamen\Reglas\EntranteDisponible`'s own docblocks, and
     * `Solicitudes\SolicitudRepository`'s class docblock for the full
     * business shape). Default: `true` (ON) — see
     * `Migrations\InitialSchema::SEED_DEFAULTS`, 'exencion_arco_activa'.
     * Unlike `prioridadPadresActiva()` above, this policy ships ON by
     * default: the process owner's own request was for a SWITCH to turn it
     * OFF when needed, not an opt-in gate for a soft preference nobody was
     * enforcing yet.
     */
    public function exencionArcoActiva(): bool {
        return $this->readBool( 'exencion_arco_activa', (string) InitialSchema::SEED_DEFAULTS['exencion_arco_activa'] );
    }

    /**
     * The operator-configured `sp_team` post id for THIS season's "lista de
     * espera" pseudo-team, ONLY when explicitly set to a positive value —
     * `null` otherwise (an absent row, or the seeded `'0'`, see
     * Migrations\InitialSchema::SEED_DEFAULTS), which tells
     * Plazas\ListaEsperaResolver to fall back to its own dynamic, slug-based
     * lookup instead of trusting a `0` as a real post id. This accessor never
     * performs that lookup itself — Settings stays a plain `cambios_settings`
     * reader, same as every other getter in this class; see
     * Plazas\ListaEsperaResolver's own docblock for the two-step resolution
     * order (this override first, the dynamic lookup second).
     */
    public function listaEsperaTeamIdOverride(): ?int {
        $value = $this->readInt( 'lista_espera_team_id', (int) InitialSchema::SEED_DEFAULTS['lista_espera_team_id'] );

        return $value > 0 ? $value : null;
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
