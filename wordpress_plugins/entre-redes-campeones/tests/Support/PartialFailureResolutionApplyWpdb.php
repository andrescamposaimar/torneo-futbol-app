<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database) whose
 * update() fails only for the SECOND link-resolution write it sees (the
 * same shape FailingResolutionApplyWpdb identifies: both estado_vinculo and
 * candidatos_json present in $data), succeeding for every other one.
 *
 * FailingResolutionApplyWpdb proves the all-fail case; this proves the
 * mixed case a single boolean return could never distinguish from either
 * extreme — RevalidationService::revalidateYear() must report succeeded
 * strictly less than total, not collapse a partial failure into either
 * "all succeeded" or "all failed" (item 2).
 */
class PartialFailureResolutionApplyWpdb extends \wpdb {

    private int $resolutionWriteCount = 0;

    public function update( string $table, array $data, array $where ): int|false {
        if ( array_key_exists( 'estado_vinculo', $data ) && array_key_exists( 'candidatos_json', $data ) ) {
            ++$this->resolutionWriteCount;

            if ( 2 === $this->resolutionWriteCount ) {
                $this->last_error = 'Simulated partial link-resolution update failure for test';
                return false;
            }
        }

        return parent::update( $table, $data, $where );
    }
}
