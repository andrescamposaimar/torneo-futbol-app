<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * WP-CLI command: wp prode seed-fecha
 *
 * Thin CLI wrapper around SeedFechaService. All business logic lives in the
 * service; this command constructs it from its deps and delegates execute().
 *
 * Design D6: SeedFechaService is shared by CLI (this command) and the admin UI
 * (SettingsPage in WU-C). Constructor signature is preserved so Plugin.php
 * requires no changes in this PR.
 *
 * The command is registered in Plugin::boot() behind a WP_CLI guard:
 *   if ( defined('WP_CLI') && WP_CLI ) {
 *       WP_CLI::add_command('prode seed-fecha', SeedFechaCommand::class);
 *   }
 *
 * Idempotency:
 *   FechaRepository::upsertFecha is idempotent — a second seed for the same
 *   play-date reuses the existing fecha row and deduplicates match rows.
 */
class SeedFechaCommand {

    private SeedFechaService $service;

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
        $this->service = new SeedFechaService( $settings, $lockComputer, $repository, $resolverFn );
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
     * Delegates to SeedFechaService::execute().
     *
     * Kept public for backward compatibility with existing tests and any
     * callers that invoke execute() directly (e.g. FechaCreationCron path).
     *
     * @return array{fecha_id: int, match_count: int, skipped: bool, reused: bool}
     */
    public function execute(): array {
        return $this->service->execute();
    }
}
