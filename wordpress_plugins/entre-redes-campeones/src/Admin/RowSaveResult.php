<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

/**
 * The outcome of TitleEditorPage::handleAddRow() / ::handleEditRow() (item
 * 3).
 *
 * A bare `?int`/`bool` return could not distinguish three outcomes that need
 * different operator-facing notices:
 *
 *  - the row itself was never saved at all (title not found, or the
 *    field write failed) — nothing happened, "try again" is honest here;
 *  - the row WAS saved but LinkResolver's follow-up write then failed —
 *    the row exists; telling the operator to retry produces a duplicate
 *    (agregar_fila) or re-runs an edit that already landed (editar_fila);
 *  - everything succeeded.
 *
 * Collapsing the middle case into the first (as the previous `null`/`false`
 * return did) is exactly the defect item 3 fixes.
 *
 * $directoryUnavailable narrows the "saved but not resolved" case further
 * (BLOCKER/CRITICAL round-2 fix): a row that was saved but whose resolution
 * could not even be ATTEMPTED because the player directory itself threw
 * PlayerDirectoryQueryException is a different situation from one whose
 * resolution ran and its write then failed — the operator-facing notice
 * needs to say "the directory is unavailable", not the generic "could not
 * evaluate" copy, so they do not waste time retrying an add/edit that
 * already landed while waiting on an external dependency.
 */
final class RowSaveResult {

    private function __construct(
        public readonly bool $rowSaved,
        public readonly bool $linkResolved,
        public readonly ?int $rowId,
        public readonly bool $directoryUnavailable = false
    ) {
    }

    public static function notSaved(): self {
        return new self( false, false, null );
    }

    public static function saved( int $rowId, bool $linkResolved ): self {
        return new self( true, $linkResolved, $rowId );
    }

    public static function savedDirectoryUnavailable( int $rowId ): self {
        return new self( true, false, $rowId, true );
    }
}
