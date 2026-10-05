=== Entre Redes — Cambios de Jugadores ===
Contributors: entreredes
Tags: football, roster, player-changes, calendar, tournament
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 0.1.9
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Jornada calendar, plazo deadlines, and estado lifecycle for the Entre Redes player-change feature.

== Description ==

The Cambios plugin models the calendar of jornadas (matchdays) for a season — which does not exist as a first-class concept in SportsPress — along with the four operational deadlines (plazos) and the estado lifecycle (programada/jugada/dirimida/suspendida) each jornada goes through.

**Requires**: the Entre Redes base plugin must be installed and active.

== Installation ==

1. Install and activate the Entre Redes base plugin.
2. Upload `entre-redes-cambios/` to the `wp-content/plugins/` directory.
3. Run `composer install --no-dev` inside the plugin directory. This plugin ships one runtime dependency, `firebase/php-jwt`, used to verify the JWTs entre-redes-prode issues.
4. Activate the plugin from the WordPress admin Plugins screen.
5. Verify: `cambios_fecha`, `cambios_fecha_partido` and `cambios_settings` tables exist, and `cambios_settings` is seeded with default values.
6. To build a deployable zip instead of a local checkout, use `wordpress_plugins/build-plugin.sh entre-redes-cambios` from the repo — see the plugin's README.md.

== Changelog ==

= 0.1.9 =
* Change: `GET /cambios/plazas/candidatos` now excludes a candidate with no resolvable puntaje from the population entirely, in BOTH screen sections ("Lista de Espera" and "Padrón Completo") as well as the default season-registered population — not just the whole padrón-wide population. Without a puntaje there is nothing to evaluate against the plaza's techo, so such a player is not a candidate at all (previously it was rendered as a non-viable row with `motivo: 'puntaje_indeterminado'`, buried last by the 0.1.8 sort). The exclusion happens in `Plazas\CandidatosResolver::buscarPaginado()`, before `X-WP-Total` is computed and before pagination, so it never produces a short page or an inflated total. `Plazas\CandidatosResolver::paraPlaza()` / `::paraSeccion()` (the padres-priority rule's own, unpaginated pool) are unchanged by this — an indeterminate puntaje was already never viable there. The dictamen engine's own refusal of an indeterminate entrante (`Dictamen\Reglas\PuntajeDentroDelTecho`) is unaffected; this is a presentation-layer exclusion, not the enforcement.

= 0.1.8 =
* Change: `GET /cambios/plazas/candidatos` now orders candidates by `puntaje DESC, nombre ASC, player_id ASC`, over the FULL filtered population before pagination (`Plazas\CandidatosResolver::buscarPaginado()`) — not just `player_id ASC`. A candidate with no resolvable puntaje sorts last. Names are resolved for the whole population via one batched (chunked, 200 ids per query) `Plazas\CandidatosResolver::nombresPorJugador()` read, never one query per candidate. The app's own client-side puntaje sort on "Pedir cambio" (which only ever re-sorted the pages loaded so far, visibly reordering rows on every `loadMore()`) is removed — the screen now renders the server's order as given.

= 0.1.7 =
* Add: `GET /cambios/plazas/candidatos` now returns each candidate's `posicion` (main `sp_position` name, e.g. `Arquero`), resolved for the whole page in a single batched `wp_get_object_terms()` call via the new `Plazas\PosicionResolver` — never one query per candidate (an N+1 was already removed from this same endpoint in 0.1.5/0.1.6; this addition is batched the same way). "Main position" mirrors `entre-redes-api`'s own `/jugadores` choice exactly, so a player never shows one position on "Pedir cambio" and a different one on the Players/Team screens.

= 0.1.6 =
* Fix: a stored puntaje of "0" (7 players in production, none registered in season 2026) made `JugadorMetricasReader::extractPuntaje()` call `Puntaje::fromDecimal('0')`, which correctly rejects 0 as out of range — the thrown `InvalidArgumentException` escaped `CandidatosResolver::buscarPaginado()` uncaught, turning `GET /cambios/plazas/candidatos?seccion=padron_completo` into a 500 for every captain. A stored puntaje of zero now reads as "sin calificar" (never a legitimate rating of zero, matching the app's own `formatearPuntaje()`), and any OTHER value that is not one of the 9 valid puntajes degrades to the same safe `null` state instead of throwing — logged as `metrics.puntaje_invalido` (player id + raw value) so a genuinely corrupt row stays visible instead of vanishing. `Puntaje::fromHalfPoints()` / `::fromDecimal()` themselves are unchanged and still throw for callers that require a valid puntaje.

= 0.1.5 =
* Fix: chunk the IN(...) clause JugadorMetricasReader builds from the whole candidate population (batches of 200) instead of one unbounded query, to harden the ?seccion=padron_completo path (~1000 players) against a production 500.
* Add: persist the most recent unexpected failure (event, exception class, message, UTC timestamp) in the entre_redes_cambios_ultimo_error option, readable via phpMyAdmin, since this host exposes no PHP error log.

= 0.1.4 =
* Fix: the candidatos search filter (`CandidatosResolver`) built a `LIKE ... ESCAPE '\'` clause that is valid under this plugin's SQLite test shim but a SQL syntax error on real MySQL, where a backslash before the closing quote escapes the quote itself instead of closing the string literal. Every captain search request failed in production. Replaced the escape character with `!`, which has no special meaning in either engine's string-literal parsing or as a `LIKE` wildcard, via a new shared `escapeLikeTerm()` helper.

= 0.1.0 =
* Initial scaffold: plugin structure, 3-table schema (cambios_fecha, cambios_fecha_partido, cambios_settings), pure PlazosCalculator and EstadoDeriver, FechaRepository, SeedTemporadaService. No REST/admin/cron consumers yet (slice 0).
