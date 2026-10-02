<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\Exception\ListaEsperaTeamUnresolvableException;

/**
 * Resolves the `sp_team` post id of THIS season's "lista de espera"
 * pseudo-team — the population `Rest\PlazasController`'s "Lista de Espera"
 * section enumerates, and the ONE team `Plazas\CandidatosResolver`'s "Padrón
 * Completo" section excludes (see that class's own docblock).
 *
 * *** WHY THIS IS NOT MATCHED BY NAME ***
 * The team is a completely ordinary `sp_team` post, renamed every year —
 * today "LISTA DE ESPERA 2026" (id 14349, slug `lista-de-espera-2026`; there
 * is also id 16089 "LISTA DE NO INSCRIPTOS 2026", a DIFFERENT pseudo-team
 * this class must never match). Matching on the human TITLE is the kind of
 * lookup this plugin's whole approach to WordPress data deliberately avoids
 * (see `Plazas\CandidatosResolver`'s own class docblock for why raw,
 * structural lookups are preferred over anything that trusts a human-typed
 * label) — a stray extra space or a re-typed accent silently breaks a match
 * on name, and a title is re-typed by a committee member every season with
 * no format guarantee at all. This class never reads `post_title`.
 *
 * *** TWO WAYS TO GET AN ANSWER, IN THIS ORDER ***
 *   1. `Calendario\Settings::listaEsperaTeamIdOverride()` — an explicit,
 *      operator-set `cambios_settings` row. Checked FIRST and trusted
 *      without any further lookup whenever it is a positive int. This is
 *      the escape hatch for the day step 2 breaks (the team renamed off the
 *      slug convention, or a season needs a different team entirely).
 *   2. DYNAMIC RESOLUTION BY SLUG, when no override is configured: read the
 *      season's own term name (`wp_terms.name`, `term_id` = the season id —
 *      confirmed to be the same id space `Calendario\Settings::seasonId()`'s
 *      own docblock describes), pull the first 4-digit year it contains
 *      (not an exact-equality match — see `resolveSeasonYear()`'s own
 *      docblock for why), and look up the PUBLISHED `sp_team` post whose
 *      slug (`post_name`) is exactly `lista-de-espera-{year}`.
 *
 * This is a deliberate trade: resolving by slug is itself a string match,
 * the exact kind of thing this plugin is normally wary of — but it is the
 * ONLY way this setting can "just work" the day a season rolls over without
 * a manual configuration step, and step 1 above exists precisely so a
 * captain is never blocked by it breaking: an operator sets the override
 * once, and this class stops guessing entirely for that season.
 *
 * *** WHY A FAILURE HERE MUST NEVER READ AS "EMPTY LIST" ***
 * See class-level reasoning in `Exception\ListaEsperaTeamUnresolvableException`'s
 * own docblock: every failure path below throws rather than returning `0`
 * or `null`, so `Rest\PlazasController::listarCandidatos()` fails loud (a
 * logged 500) instead of silently rendering "Lista de Espera" as empty.
 *
 * Not `final` — mocked as a collaborator by Rest\PlazasControllerTest,
 * exactly like PlazaRepository / FechaRepository / CapitanAuthorizer /
 * CandidatosResolver (none of which are `final` either, for the same
 * reason: PHPUnit's `createMock()` cannot double a `final` class).
 */
class ListaEsperaResolver {

    private \wpdb $wpdb;
    private Settings $settings;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, Settings $settings, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->settings = $settings;
        $this->eventLog = $eventLog;
    }

    /**
     * @throws ListaEsperaTeamUnresolvableException When no override is
     *         configured AND the dynamic slug lookup cannot produce a team
     *         id — see class docblock, "TWO WAYS TO GET AN ANSWER".
     */
    public function resolve( int $seasonId ): int {
        $override = $this->settings->listaEsperaTeamIdOverride();

        if ( null !== $override ) {
            return $override;
        }

        $year = $this->resolveSeasonYear( $seasonId );

        if ( null === $year ) {
            throw $this->failure(
                "season {$seasonId}'s own term could not be read, or its name carries no 4-digit year",
                [ 'season_id' => $seasonId ]
            );
        }

        $slug   = "lista-de-espera-{$year}";
        $teamId = $this->findPublishedTeamBySlug( $slug );

        if ( null === $teamId ) {
            throw $this->failure(
                "no published sp_team post has the slug '{$slug}'",
                [ 'season_id' => $seasonId, 'slug' => $slug ]
            );
        }

        return $teamId;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @return string|null A 4-digit year (e.g. "2026") found anywhere inside
     *         the season's own term name, or null when the term does not
     *         exist or its name contains no such substring.
     *
     * Deliberately NOT an exact-equality check against the term's name —
     * this plugin has no confirmed guarantee the season term is named
     * literally "2026" and nothing else (c.f. league names, which embed a
     * year alongside other words — see Calendario\LigaResolver's own class
     * docblock, "THE TWO-DASH TRAP", for a sibling example of a human-typed
     * label this codebase deliberately does not assume an exact shape for).
     * Searching for the 4-digit substring anywhere in the name matches a
     * term named exactly "2026" AND one named e.g. "Temporada 2026" without
     * needing to know in advance which shape the operator used.
     */
    private function resolveSeasonYear( int $seasonId ): ?string {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $name = $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM {$p}terms WHERE term_id = %d LIMIT 1", $seasonId )
        );

        if ( null === $name ) {
            return null;
        }

        if ( 1 !== preg_match( '/(20\d{2})/', (string) $name, $matches ) ) {
            return null;
        }

        return $matches[1];
    }

    private function findPublishedTeamBySlug( string $slug ): ?int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$p}posts
                  WHERE post_type = 'sp_team' AND post_status = 'publish' AND post_name = %s
                  LIMIT 1",
                $slug
            )
        );

        return null !== $id ? (int) $id : null;
    }

    /** @param array<string, mixed> $contexto */
    private function failure( string $motivo, array $contexto ): ListaEsperaTeamUnresolvableException {
        $this->eventLog->record( 'lectura.fallida', array_merge( $contexto, [
            'operacion' => 'ListaEsperaResolver::resolve',
            'motivo'    => $motivo,
        ] ) );

        return new ListaEsperaTeamUnresolvableException( $motivo );
    }
}
