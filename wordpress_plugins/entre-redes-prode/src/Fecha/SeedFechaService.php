<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * Core seed-fecha business logic, shared by SeedFechaCommand (CLI) and
 * SettingsPage (admin UI).
 *
 * Design D6: extracted from SeedFechaCommand::execute() so the same
 * resolve → lock → upsert pipeline can be called without any WP_CLI coupling.
 * SeedFechaCommand delegates to this service; SettingsPage injects it directly.
 *
 * Constructor signature matches SeedFechaCommand for easy DI from Plugin.php
 * in WU-C when full admin wiring is added.
 */
class SeedFechaService {

    private Settings $settings;
    private LockComputer $lockComputer;
    private FechaRepository $repository;
    /** @var callable */
    private $resolverFn;

    /**
     * @param callable $resolverFn  Returns array|null from FechaResolver::resolveNext().
     *                              In production: a closure wrapping FechaResolver::resolveNext().
     *                              In tests: a stub closure returning canned data.
     */
    public function __construct(
        Settings $settings,
        LockComputer $lockComputer,
        FechaRepository $repository,
        callable $resolverFn
    ) {
        $this->settings     = $settings;
        $this->lockComputer = $lockComputer;
        $this->repository   = $repository;
        $this->resolverFn   = $resolverFn;
    }

    /**
     * Runs the full fecha creation pipeline (Resolve → Lock → Upsert).
     *
     * Idempotent: FechaRepository::upsertFecha reuses an existing row when
     * the same play-date is seeded a second time.
     *
     * @return array{fecha_id: int, match_count: int, skipped: bool, reused: bool}
     */
    public function execute(): array {
        $result = ( $this->resolverFn )();

        if ( null === $result ) {
            return [
                'fecha_id'    => 0,
                'match_count' => 0,
                'skipped'     => true,
                'reused'      => false,
            ];
        }

        $lockedAt = $this->lockComputer->computeLockedAt(
            $result['earliest_kickoff'],
            $this->settings->lockHoursBefore()
        );

        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $seasonId = $this->settings->seasonId();

        // Detect pre-existence BEFORE upsert so the operator gets accurate
        // "created" vs "already exists" feedback. upsertFecha reuses the row
        // either way (idempotent), but the return value alone can't tell the
        // two apart, so we compare the resolved play-date against any active
        // fecha already persisted for this tenant+season.
        $reused   = false;
        $playDate = substr( min( array_column( $result['matches'], 'kickoff' ) ), 0, 10 );
        $existing = $this->repository->findActiveFecha( $tenantId, $seasonId );
        if ( null !== $existing && ! empty( $existing['matches'] ) ) {
            $existingPlayDate = substr(
                min( array_column( $existing['matches'], 'match_kickoff' ) ),
                0,
                10
            );
            $reused = ( $existingPlayDate === $playDate );
        }

        $fechaId = $this->repository->upsertFecha(
            $tenantId,
            $seasonId,
            $lockedAt,
            $result['matches']
        );

        return [
            'fecha_id'    => $fechaId,
            'match_count' => count( $result['matches'] ),
            'skipped'     => false,
            'reused'      => $reused,
        ];
    }
}
