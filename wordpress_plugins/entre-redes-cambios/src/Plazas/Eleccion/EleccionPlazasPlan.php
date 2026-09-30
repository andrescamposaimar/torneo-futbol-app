<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * The result of `EleccionImporter::planificar()` — every problem the whole
 * election import has, and (only when there are none) every plaza it would
 * open plus every captain it would designate, grouped by team. Immutable,
 * same discipline as `Plazas\PlazaImportPlan` (which this class replaces —
 * see `EleccionImporter`'s own class docblock): built once, in full, before
 * either the CLI tool prints it or `EleccionImporter::aplicarPlazas()` /
 * `aplicarCapitanes()` are allowed to write anything.
 *
 * *** ONE PLAN, TWO INDEPENDENT WRITES ***
 * Resolving a team's 11 titulares (names, ids, puntajes) and resolving its
 * captain happen in the SAME validation pass — a captain IS one of the 11
 * titulares, so re-resolving their name a second time could only ever
 * diverge from the first resolution, never improve on it. What stays
 * independent is WRITING: `rowsToOpen()` feeds `aplicarPlazas()`,
 * `capitanesPorEquipo()` feeds `aplicarCapitanes()`, and the CLI tool gates
 * each behind its own flag (`--apply` / `--apply-capitanes`) — see
 * `tools/importar-eleccion.php`. Both writes refuse outright while
 * `hasErrors()` is true.
 */
final class EleccionPlazasPlan {

    /** @var array<int, string> */
    private array $errors;

    /** @var array<int, string> */
    private array $warnings;

    /** @var array<int, array{team_id:int, team_label:string, estado:string, titular_count:int}> */
    private array $teamSummaries;

    /** @var array<int, array{team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}> */
    private array $rowsToOpen;

    /** @var array<int, array{team_id:int, team_label:string, player_id:int}> */
    private array $capitanes;

    /** @var array<int, array<int, int>> team_id => list of titular_player_id, only for teams that fully resolved. */
    private array $officialTitularesByTeam;

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     * @param array<int, array{team_id:int, team_label:string, estado:string, titular_count:int}> $teamSummaries
     * @param array<int, array{team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}> $rowsToOpen
     * @param array<int, array{team_id:int, team_label:string, player_id:int}> $capitanes
     * @param array<int, array<int, int>> $officialTitularesByTeam
     */
    public function __construct(
        array $errors,
        array $warnings,
        array $teamSummaries,
        array $rowsToOpen,
        array $capitanes,
        array $officialTitularesByTeam
    ) {
        $this->errors                  = $errors;
        $this->warnings                = $warnings;
        $this->teamSummaries           = $teamSummaries;
        $this->rowsToOpen              = $rowsToOpen;
        $this->capitanes               = $capitanes;
        $this->officialTitularesByTeam = $officialTitularesByTeam;
    }

    public function hasErrors(): bool {
        return [] !== $this->errors;
    }

    /** @return array<int, string> */
    public function errors(): array {
        return $this->errors;
    }

    /** @return array<int, string> */
    public function warnings(): array {
        return $this->warnings;
    }

    /** @return array<int, array{team_id:int, team_label:string, estado:string, titular_count:int}> */
    public function teamSummaries(): array {
        return $this->teamSummaries;
    }

    /**
     * Every plaza `EleccionImporter::aplicarPlazas()` would actually open —
     * empty whenever `hasErrors()` is true, and empty for a team already
     * fully imported (same idempotency key as the importer this replaces:
     * `(season_id, team_id, titular_player_id)` — see `EleccionImporter`'s
     * class docblock).
     *
     * @return array<int, array{team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}>
     */
    public function rowsToOpen(): array {
        return $this->rowsToOpen;
    }

    /**
     * Every captain `EleccionImporter::aplicarCapitanes()` would designate —
     * empty whenever `hasErrors()` is true.
     *
     * @return array<int, array{team_id:int, team_label:string, player_id:int}>
     */
    public function capitanes(): array {
        return $this->capitanes;
    }

    /**
     * The 11 official titulares of every team whose OWN rows resolved
     * cleanly — independent of whether that team's plazas are `a_importar`,
     * `ya_importado` or `conflicto` (a team already imported, or one that
     * conflicts with existing data, still HAS a known, correct set of 11
     * titulares; only a team with its own resolution errors contributes
     * nothing here). Feeds `EleccionImporter::reportarReemplazos()` — the
     * "who is an official titular" side of that report.
     *
     * @return array<int, array<int, int>> team_id => list of titular_player_id.
     */
    public function officialTitularesByTeam(): array {
        return $this->officialTitularesByTeam;
    }
}
