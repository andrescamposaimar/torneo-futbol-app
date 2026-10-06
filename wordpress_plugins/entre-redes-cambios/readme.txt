=== Entre Redes — Cambios de Jugadores ===
Contributors: entreredes
Tags: football, roster, player-changes, calendar, tournament
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 0.1.13
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

= 0.1.13 =
* Add: `cambios_plaza` gains `es_arco TINYINT(1) NOT NULL DEFAULT 0` — "is this the goalkeeper's plaza" is now a STORED fact instead of a derivation re-computed on every read from the titular's `sp_position`. Slice 1 of the "exención del arco" feature (later slices add the grouped request type and its UI). Written once: `Plazas\PlazaRepository::doOpenPlaza()` derives and persists it for every NEW plaza (`PosicionResolver::esPosicionDelArqueroTitular()`, term 3 "Arquero" ONLY — never term 125 "Arquero Sup."), and `Migrations\MigrationRunner::backfillEsArco()` derives it once, idempotently, for the 330 plazas that existed in production before this column did.
* Add: `Migrations\MigrationRunner::checkEsArcoInvariant()` — loudly verifies, after every migration run, that exactly one `es_arco=1` plaza exists per `(season_id, team_id)` among the open plazas (zero or two is a violation), recording an `arco.invariante_violada` EventLog event plus an `admin_notice`, same discipline as the existing `checkStorageEngine()`.
* Add: `Plazas\Alta\TitularesListImporter::planificar()` now refuses a team whose 11 titulares include zero — or more than one — resolved titular goalkeeper, naming the team, mirroring the existing `es_capitan` "exactly one" check.
* Change: `Dictamen\DictamenContextAssembler` and `Plazas\CandidatosResolver::buscarPaginado()` now read the plaza's stored `es_arco` column instead of re-deriving "is this the goalkeeper's plaza" from the titular's current `sp_position` on every call — a titular's position changing later in WordPress (e.g. a data-entry fix) no longer silently moves which plaza is "the goal" out from under an in-flight solicitud or a captain's candidate list. `PosicionResolver::esPosicionDeArquero()` (the CANDIDATE definition, term 3 OR 125) is unchanged and still resolved live — only the PLAZA definition moved to the stored column.

= 0.1.12 =
* Add: a new dictamen rule, `Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo` (motivo `arquero_no_ocupa_plaza_de_campo`) — a goalkeeper may not take over a plaza that is not the goalkeeper's plaza. "Goalkeeper" (the CANDIDATE definition) means `sp_position` term 3 ("Arquero") OR term 125 ("Arquero Sup." — the process owner confirmed explicitly that a backup goalkeeper counts); "the goalkeeper's plaza" (the PLAZA definition) means the plaza's TITULAR position is term 3 ONLY — there are exactly 30 of those per season, one per team, which is what keeps "the goal" an identifiable, singular plaza. The two definitions are deliberately disjoint and are never unified. The rule is asymmetric on purpose: it only blocks goalkeeper → field plaza, never field player → goalkeeper's plaza (the pending "exención del arco" direction stays legitimate). Registered in `DictamenEngineFactory::reglas()` (now ten rules) — `Solicitudes\SolicitudRepository::publicarLote()` re-runs the full dictamen before applying, so a hand-crafted or stale-client request is still refused here regardless of what the candidate list showed.
* Change: `Plazas\CandidatosResolver::buscarPaginado()` now also excludes a goalkeeper from a FIELD plaza's candidate population — a convenience mirroring the dictamen rule above (never a substitute for it), applied in the same place and for the same reason as the existing unrated/over-ceiling population filters: after the population's metrics are in hand, before `total` is computed, before `array_slice()` takes a page. The position lookup for the whole population (plus the plaza's own titular) runs in exactly ONE batched `Plazas\PosicionResolver::resolverParaIds()` call, never one per candidate.
* Add (app): "Mis Solicitudes" now renders a plain-Spanish message for `arquero_no_ocupa_plaza_de_campo` via `cambiosMotivoMensaje()` instead of a raw code.

= 0.1.11 =
* Add: `cambios_solicitud` gains `saliente_player_id`, captured once at `Solicitudes\SolicitudRepository::crear()` time from the plaza's vigent ocupación — never re-derived later, so an old solicitud keeps naming who actually left even after the plaza's chain has since advanced. `GET /cambios/solicitudes` now returns `sale`/`entra` objects (`{ player_id, nombre, puntaje }`) for every row: for a `sustitucion`, `sale` is this stored saliente and `entra` is the stored `entrante_player_id`; for a `regreso` (whose `entrante_player_id` is always NULL — who returns is never a choice the request makes), `entra` is instead the plaza's permanent `titular_player_id` and `sale` is the same stored saliente — the suplente the titular displaces. Names and puntajes for the whole page are resolved in one batched call each (`get_posts()`/`get_the_title()` cache priming, `Plazas\JugadorMetricasReader::resolveMuchos()`), never one query per row. An unresolvable puntaje stays `null` rather than a fabricated value; a `saliente_player_id` that predates this column (a row created before 0.1.11) degrades its whole `sale` side to `{ player_id: null, nombre: null, puntaje: null }` rather than guessing.
* Add (app): "Mis Solicitudes" now renders `Sale: <Apellido, Nombre> [<puntaje>]` and `Entra: <Apellido, Nombre> [<puntaje>]` under each pedido, using the app's existing `formatearPuntaje()` so a whole-number puntaje never renders with a spurious decimal. An unknown puntaje omits the brackets entirely rather than showing `[]` or `[-]`; an unrecorded side (predates the backend column) renders as "Sin registrar" instead of a blank line.

= 0.1.10 =
* Fix: `GET /cambios/plazas/candidatos` could return an EMPTY page while `X-WP-Total` reported a population in the hundreds — the exact production incident: opening "Padrón Completo" with no puntaje chip selected showed "No encontramos candidatos disponibles para esta plaza" on a plaza with a 4.5 techo, even though 81 published players were rated 4.5 and below. Root cause: `Plazas\CandidatosResolver::buscarPaginado()` sorts `puntaje DESC` (0.1.8), and the techo was only enforced AFTER a page was already sliced — so the 31 published players rated 5.0 alone filled page 1 at `per_page=20`, and the per-page filter then stripped every one of them, returning an empty page. The techo now excludes an over-ceiling candidate from the POPULATION itself, in the same place and for the same reason as the unrated-candidate exclusion added in 0.1.9 — before `X-WP-Total` is computed and before pagination. `?incluir_no_viables=1` can no longer surface a `motivo: 'puntaje_excede_techo'` row (there is nothing left in the population for it to surface) but still surfaces a page's own phase-2 rejections (`ocupa_otra_plaza_vigente`, `bloqueado_por_cierre_truncado`) unchanged. `total` is therefore very slightly optimistic — it counts the population before phase-2 verdicts — a materially smaller and more even-handed overcount than the one this removes, since phase-2 non-viables are a small minority scattered across the whole population rather than concentrated at the top of the sort.
* Fix (app): the Flutter app rendered the empty state the instant a page arrived with zero candidates, even when the server reported more pages remain (`hasMore: true`) — the app-side half of the same incident, since a page can still legitimately come back short or empty after the backend's own per-page phase-2 filter runs. `CambiosCandidatosController` now keeps fetching subsequent pages, without showing the empty state, until a page returns at least one candidate, the server reports no more pages, or a small cap on consecutive empty pages is reached (a defensive bound against a server that kept returning empty pages forever).

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
