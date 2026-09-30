<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * A READ-ONLY diagnostic — `EleccionImporter::reportarReemplazos()` never
 * writes anything, and nothing in this class gates `aplicarPlazas()` or
 * `aplicarCapitanes()`. It exists because the operator needs to know, before
 * or after importing, where the "who currently occupies each plaza" data
 * (deliberately OUT of scope for this importer — see `EleccionImporter`'s
 * class docblock) already looks inconsistent on WordPress:
 *
 *   - `titulares_con_baja`: of a team's 11 OFFICIAL titulares (from
 *     `EleccionPlazasPlan::officialTitularesByTeam()`), how many carry the
 *     `sp_position` `reemplazo_baja` flag (term 156) — an official titular
 *     WordPress marks as currently replaced.
 *   - `altas_no_titulares`: of that SAME team's WordPress roster (see
 *     `EleccionImporter::equipoRoster()`), how many players are NOT one of
 *     the 11 official titulares but DO carry the `reemplazo_alta` flag (term
 *     155) — a plausible "this person is filling in" signal.
 *   - `difieren`: true when those two counts are not equal — the team's
 *     "who is out" and "who is in instead" signals do not balance, which the
 *     operator needs to look at manually; this importer never resolves it.
 *
 * `extras()` lists every player on a team's WordPress roster who is NEITHER
 * an official titular NOR flagged `reemplazo_alta` — someone the roster
 * disagrees with the official plaza list about, in the other direction.
 */
final class ReemplazoReport {

    /** @var array<int, array{team_id:int, team_label:string, titulares_con_baja:int, altas_no_titulares:int, difieren:bool}> */
    private array $porEquipo;

    /** @var array<int, array{team_id:int, team_label:string, player_id:int, player_label:string}> */
    private array $extras;

    /** @var array<int, string> Teams skipped because their official titulares never resolved. */
    private array $omitidos;

    /**
     * @param array<int, array{team_id:int, team_label:string, titulares_con_baja:int, altas_no_titulares:int, difieren:bool}> $porEquipo
     * @param array<int, array{team_id:int, team_label:string, player_id:int, player_label:string}> $extras
     * @param array<int, string> $omitidos
     */
    public function __construct( array $porEquipo, array $extras, array $omitidos ) {
        $this->porEquipo = $porEquipo;
        $this->extras    = $extras;
        $this->omitidos  = $omitidos;
    }

    /** @return array<int, array{team_id:int, team_label:string, titulares_con_baja:int, altas_no_titulares:int, difieren:bool}> */
    public function porEquipo(): array {
        return $this->porEquipo;
    }

    /** @return array<int, array{team_id:int, team_label:string, titulares_con_baja:int, altas_no_titulares:int, difieren:bool}> */
    public function equiposQueDifieren(): array {
        return array_values( array_filter( $this->porEquipo, static fn ( array $e ): bool => $e['difieren'] ) );
    }

    /** @return array<int, array{team_id:int, team_label:string, player_id:int, player_label:string}> */
    public function extras(): array {
        return $this->extras;
    }

    /** @return array<int, string> */
    public function omitidos(): array {
        return $this->omitidos;
    }
}
