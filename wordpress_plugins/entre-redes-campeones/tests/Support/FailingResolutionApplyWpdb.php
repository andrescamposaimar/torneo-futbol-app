<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database) whose
 * update() fails specifically for a link-resolution write — LinkWriteService
 * ::applyResolution()'s and ::setManualLink()'s shared shape, identified by
 * the presence of both estado_vinculo and candidatos_json in $data — while
 * leaving every other update() (a plain row name/captain/orden edit, a
 * title header edit, ...) untouched.
 *
 * Used to prove that RevalidationService::revalidateYear() and
 * TitleEditorPage::handleAddRow()/handleEditRow() fold applyResolution()'s
 * boolean into what they report, instead of discarding it and always
 * claiming success (item 6).
 */
class FailingResolutionApplyWpdb extends \wpdb {

    public function update( string $table, array $data, array $where ): int|false {
        if ( array_key_exists( 'estado_vinculo', $data ) && array_key_exists( 'candidatos_json', $data ) ) {
            $this->last_error = 'Simulated link-resolution update failure for test';
            return false;
        }

        return parent::update( $table, $data, $where );
    }
}
