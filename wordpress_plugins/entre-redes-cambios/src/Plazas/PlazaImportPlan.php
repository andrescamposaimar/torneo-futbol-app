<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * The result of `Plazas\PlazaImporter::planificar()` — every problem the
 * whole CSV has, and (only when there are none) every plaza it would open,
 * grouped by team. Immutable: `PlazaImporter` builds one of these once, in
 * full, before either the CLI tool prints it (dry-run) or
 * `PlazaImporter::aplicar()` is allowed to write anything.
 *
 * *** WHY hasErrors() GATES aplicar() ENTIRELY ***
 * See `Plazas\PlazaImporter`'s class docblock, "VALIDATE THE WHOLE FILE
 * FIRST": a partial roster is worse than none, because the captain-facing
 * screens would then show some teams a plausible, wrong squad. `aplicar()`
 * refuses to run at all when `hasErrors()` is true — see that method's own
 * docblock.
 */
final class PlazaImportPlan {

    /** @var array<int, string> */
    private array $errors;

    /** @var array<int, string> */
    private array $warnings;

    /** @var array<int, array{team_id:int, team_label:string, plazas:int, estado:string, lines:array<int,int>}> */
    private array $teamSummaries;

    /** @var array<int, array{line:int, team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}> */
    private array $rowsToOpen;

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     * @param array<int, array{team_id:int, team_label:string, plazas:int, estado:string, lines:array<int,int>}> $teamSummaries
     * @param array<int, array{line:int, team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}> $rowsToOpen
     */
    public function __construct( array $errors, array $warnings, array $teamSummaries, array $rowsToOpen ) {
        $this->errors        = $errors;
        $this->warnings      = $warnings;
        $this->teamSummaries = $teamSummaries;
        $this->rowsToOpen    = $rowsToOpen;
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

    /**
     * One entry per DISTINCT team the CSV mentions, whether it will be
     * imported, skipped as already-imported, or could not be summarized
     * because one of its own rows failed validation.
     *
     * @return array<int, array{team_id:int, team_label:string, plazas:int, estado:string, lines:array<int,int>}>
     */
    public function teamSummaries(): array {
        return $this->teamSummaries;
    }

    /**
     * Every row `PlazaImporter::aplicar()` would actually open — empty
     * whenever hasErrors() is true. A team already fully imported
     * contributes NOTHING here (see class docblock on `Plazas\PlazaImporter`,
     * "IDEMPOTENCY KEY").
     *
     * @return array<int, array{line:int, team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}>
     */
    public function rowsToOpen(): array {
        return $this->rowsToOpen;
    }
}
