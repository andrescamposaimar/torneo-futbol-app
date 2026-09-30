<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Alta;

use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * The result of `TitularesListImporter::planificar()` — every problem the
 * whole backfill has, and (only when there are none) every plaza it would
 * open plus every captain it would designate. Immutable, built once, in
 * full, before either the CLI tool prints it or
 * `TitularesListImporter::aplicarPlazas()` / `::aplicarCapitanes()` are
 * allowed to write anything — same discipline as the plan class of this
 * plugin's previous spreadsheet-based importer, which this class replaces
 * (see `TitularesListImporter`'s own class docblock for why that whole
 * namespace was removed).
 *
 * *** ONE PLAN, TWO INDEPENDENT WRITES *** `rowsToOpen()` feeds
 * `aplicarPlazas()`, `capitanes()` feeds `aplicarCapitanes()`, and the CLI
 * tool gates each behind its own flag (`--apply` / `--apply-capitanes`) —
 * see `tools/importar-titulares.php`. Both writes refuse outright while
 * `hasErrors()` is true, and each can run independently of the other.
 *
 * *** ERRORS VS. WARNINGS *** A team whose row count is not exactly 11 is a
 * `warnings()` entry, never an `errors()` one — the task this class backs is
 * explicitly a MID-SEASON backfill tool too, and a real squad can legitimately
 * have fewer or more than 11 rows at the moment it is loaded (an injury
 * replacement already resolved, a plaza not yet filled). Refusing the whole
 * load over that would defeat the backfill's own purpose. Every other
 * problem this class can hold — an id that does not resolve, a duplicate
 * titular, more than one (or zero) captains for a team, a puntaje outside
 * the 9 valid values — is an `errors()` entry, because none of those has a
 * legitimate reading: they are either a typo in the input or a real
 * contradiction in the data.
 */
final class TitularesListPlan {

    /** @var array<int, string> */
    private array $errors;

    /** @var array<int, string> */
    private array $warnings;

    /** @var array<int, array{team_id:int, team_label:string, estado:string, row_count:int}> */
    private array $teamSummaries;

    /** @var array<int, array{team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}> */
    private array $rowsToOpen;

    /** @var array<int, array{team_id:int, team_label:string, player_id:int}> */
    private array $capitanes;

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     * @param array<int, array{team_id:int, team_label:string, estado:string, row_count:int}> $teamSummaries
     * @param array<int, array{team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}> $rowsToOpen
     * @param array<int, array{team_id:int, team_label:string, player_id:int}> $capitanes
     */
    public function __construct(
        array $errors,
        array $warnings,
        array $teamSummaries,
        array $rowsToOpen,
        array $capitanes
    ) {
        $this->errors        = $errors;
        $this->warnings       = $warnings;
        $this->teamSummaries = $teamSummaries;
        $this->rowsToOpen    = $rowsToOpen;
        $this->capitanes     = $capitanes;
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

    /** @return array<int, array{team_id:int, team_label:string, estado:string, row_count:int}> */
    public function teamSummaries(): array {
        return $this->teamSummaries;
    }

    /**
     * Every plaza `TitularesListImporter::aplicarPlazas()` would actually
     * open — empty whenever `hasErrors()` is true, and empty for a team
     * already fully imported (idempotency key: `(season_id, team_id,
     * titular_player_id)`, exactly like the importer this class's own
     * importer replaces).
     *
     * @return array<int, array{team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}>
     */
    public function rowsToOpen(): array {
        return $this->rowsToOpen;
    }

    /**
     * Every captain `TitularesListImporter::aplicarCapitanes()` would
     * designate — empty whenever `hasErrors()` is true.
     *
     * @return array<int, array{team_id:int, team_label:string, player_id:int}>
     */
    public function capitanes(): array {
        return $this->capitanes;
    }
}
