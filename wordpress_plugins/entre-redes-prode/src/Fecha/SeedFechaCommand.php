<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * WP-CLI command: wp prode seed-fecha
 *
 * Runs the full fecha creation pipeline (Resolve → Lock → Upsert) against the
 * live deployed plugin. Useful for E2E seeding before a production redeploy.
 *
 * Design (ADR-G0-7 extended):
 *   All logic is extracted into the testable execute() method. The WP-CLI
 *   entrypoint __invoke() is a thin wrapper that calls execute() and prints
 *   human-readable output via WP_CLI::success / WP_CLI::line.
 *
 *   The command is registered in Plugin::boot() behind a WP_CLI guard:
 *     if ( defined('WP_CLI') && WP_CLI ) {
 *         WP_CLI::add_command('prode seed-fecha', SeedFechaCommand::class);
 *     }
 *
 * Idempotency:
 *   FechaRepository::upsertFecha is idempotent — a second seed for the same
 *   play-date reuses the existing fecha row and deduplicates match rows.
 */
class SeedFechaCommand {

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
     * WP-CLI entry point.
     *
     * @param array<int,   string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function __invoke( array $args, array $assoc_args ): void {
        $result = $this->execute();

        if ( $result['skipped'] ) {
            \WP_CLI::line( 'Skipped: no upcoming matches found for next play-date.' );
            return;
        }

        if ( $result['reused'] ) {
            \WP_CLI::success(
                "Already exists: fecha_id={$result['fecha_id']} with {$result['match_count']} matches (no new fecha created)."
            );
            return;
        }

        \WP_CLI::success(
            "Created fecha_id={$result['fecha_id']} with {$result['match_count']} matches."
        );
    }

    /**
     * Core logic — fully testable without WP_CLI.
     *
     * Mirrors the FechaCreationCron::execute() compose path so both
     * the cron and the seed command share the same resolve→lock→upsert
     * logic without duplicating implementation.
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
