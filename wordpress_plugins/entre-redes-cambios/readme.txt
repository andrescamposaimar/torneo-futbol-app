=== Entre Redes — Cambios de Jugadores ===
Contributors: entreredes
Tags: football, roster, player-changes, calendar, tournament
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 0.1.6
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

= 0.1.6 =
* Fix: a stored puntaje of "0" (7 players in production, none registered in season 2026) made `JugadorMetricasReader::extractPuntaje()` call `Puntaje::fromDecimal('0')`, which correctly rejects 0 as out of range — the thrown `InvalidArgumentException` escaped `CandidatosResolver::buscarPaginado()` uncaught, turning `GET /cambios/plazas/candidatos?seccion=padron_completo` into a 500 for every captain. A stored puntaje of zero now reads as "sin calificar" (never a legitimate rating of zero, matching the app's own `formatearPuntaje()`), and any OTHER value that is not one of the 9 valid puntajes degrades to the same safe `null` state instead of throwing — logged as `metrics.puntaje_invalido` (player id + raw value) so a genuinely corrupt row stays visible instead of vanishing. `Puntaje::fromHalfPoints()` / `::fromDecimal()` themselves are unchanged and still throw for callers that require a valid puntaje.

= 0.1.5 =
* Fix: chunk the IN(...) clause JugadorMetricasReader builds from the whole candidate population (batches of 200) instead of one unbounded query, to harden the ?seccion=padron_completo path (~1000 players) against a production 500.
* Add: persist the most recent unexpected failure (event, exception class, message, UTC timestamp) in the entre_redes_cambios_ultimo_error option, readable via phpMyAdmin, since this host exposes no PHP error log.

= 0.1.4 =
* Fix: the candidatos search filter (`CandidatosResolver`) built a `LIKE ... ESCAPE '\'` clause that is valid under this plugin's SQLite test shim but a SQL syntax error on real MySQL, where a backslash before the closing quote escapes the quote itself instead of closing the string literal. Every captain search request failed in production. Replaced the escape character with `!`, which has no special meaning in either engine's string-literal parsing or as a `LIKE` wildcard, via a new shared `escapeLikeTerm()` helper.

= 0.1.0 =
* Initial scaffold: plugin structure, 3-table schema (cambios_fecha, cambios_fecha_partido, cambios_settings), pure PlazosCalculator and EstadoDeriver, FechaRepository, SeedTemporadaService. No REST/admin/cron consumers yet (slice 0).
