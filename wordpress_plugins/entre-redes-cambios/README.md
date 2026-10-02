# Entre Redes — Cambios de Jugadores

WordPress plugin that models the jornada calendar, plazo deadlines, and estado lifecycle for the Entre Redes player-change feature.

## Requirements

- PHP 8.2+
- WordPress 6.2+
- MySQL with InnoDB engine
- Entre Redes base plugin (active)
- `entre-redes-prode` plugin (active) — slice 1 reads its `prode_users` table and its RSA public key; see "Captaincy and authorization" below
- Composer — from slice 1 onward this plugin ships one runtime dependency, `firebase/php-jwt` (`^7.0`, the same major entre-redes-prode uses), to verify the JWTs prode issues. Slice 0 shipped zero runtime packages; that changed the moment authorization needed to read a signed token.

## Quick start

```bash
# 1. Install dev dependencies (PHPUnit)
composer install

# 2. Activate plugin in WP admin — creates the 6 cambios_ tables and seeds
#    cambios_settings with the default timezone, season_id and plazo offsets.

# 3. Run the test suite
composer test
```

## Building the deployable zip

Run `../build-plugin.sh entre-redes-cambios` from `wordpress_plugins/`. It runs
`composer install --no-dev`, verifies `vendor/firebase/php-jwt` is present and
actually verifies a signed token (not just a class-exists check), and refuses
to produce a zip that would ship without it — the same gate that exists
because an early entre-redes-prode release once shipped `vendor/`-less and
still reported a healthy healthcheck.

## Datetime columns are UTC

Every `DATETIME` column this plugin persists (`created_at`, `updated_at`, `solicitada_at`, `resuelta_at`, `designado_at`, `revocado_at`, `closed_at`, `estado_actualizado_at`, `cambios_settings.updated_at`, etc.) is stored in **UTC**, never the site's local civil time. Production code that needs "now" as a DATETIME string calls `current_time('mysql', true)` — the explicit `$gmt = true` argument — or derives it with `gmdate()` from an epoch already in hand (see `Rest\SolicitudesController::crear()`, which takes a single epoch instant and derives both its UTC DATETIME string and its `solicitud_instante_epoch` column from that SAME value, rather than reading the clock twice). `current_time('mysql')` **without** the second argument returns the site's LOCAL time — using it for a persisted column would silently write a wrong instant offset by the site's timezone. `Calendario\PlazosCalculator::computeUtc()` is the one place a CIVIL deadline (Buenos Aires wall-clock) is deliberately converted to UTC for exactly this reason — see that class's own docblock.

## Table structure

The plugin creates 7 custom tables prefixed with `{wp_prefix}cambios_`:

| Table | Purpose |
|-------|---------|
| `cambios_fecha` | One row per jornada of the ENTIRE season (Apertura + Clausura together) — never per-zone |
| `cambios_fecha_partido` | Bridge to the `sp_event` matches that belong to a fecha |
| `cambios_settings` | Operator-configurable parameters: timezone, season_id, and the four plazo offsets |
| `cambios_capitan` | One row per captaincy DESIGNATION (history + current state) — see "Captaincy and authorization" below |
| `cambios_plaza` | One row per PLAZA of a team's roster (the aggregate of the player-change model) — see "Plazas and ocupaciones" below |
| `cambios_ocupacion` | One row per LINK in a plaza's chain of successive occupations — see "Plazas and ocupaciones" below |
| `cambios_solicitud` | One row per SOLICITUD DE CAMBIO, from submission through its whole lifecycle — see "Solicitudes de cambio: ciclo de vida" below |

## Identity: a fecha is its partidos, not its day

**The identity of a fecha is the SET OF ITS PARTIDOS (match_ids), never its `play_date`.** This is the core model this plugin depends on, and it exists because of a real operational fact discovered after the first version of this schema shipped: when a jornada is suspended, a person on the committee edits the `sp_event` date of **every partido of that jornada** directly in WordPress — normally moving the whole jornada to the following Saturday. Nothing marks this anywhere; the match_ids simply carry a new kickoff date. (This is also why the committee loads the fixture week by week instead of all at once — postponing a jornada would otherwise force re-running the entire not-yet-played fixture.)

Because of this, `Calendario\FechaRepository::upsertFecha()` resolves the existing fecha by looking up the incoming partidos' match_ids in `cambios_fecha_partido` — **never** by `(season_id, play_date)`. If those match_ids already belong to a fecha, that fecha's `play_date` **moves** (an UPDATE); it is never duplicated into a second row for the new day. `play_date_original` (set once, at creation, never touched again) and `veces_postergada` (incremented every time `play_date` advances) exist purely to make a postponement visible after the fact.

If the incoming match_ids resolve to **more than one** existing fecha, `upsertFecha()` throws — two jornadas merging into one is not something this class can resolve safely on its own; it fails loud instead of silently corrupting the resolved-fechas counter below.

## `orden` vs `numero_en_torneo`

`cambios_fecha` deliberately carries two different counters, and mixing them up is the single easiest mistake to make against this schema:

- **`orden`** is a continuous integer, 1..N, spanning the WHOLE season — it does not reset between torneos (`Clasificacion` → `Apertura` → `Clausura`). This is the ONLY field valid for arithmetic: "how many resolved fechas have passed since a given fecha" is an `orden >= N` comparison, never a `numero_en_torneo >= N` one.
- **`numero_en_torneo`** is what a human reads ("Fecha 3 del Apertura") and resets to 1 whenever `torneo_label` changes. It exists purely for display; using it in a date/count computation will silently produce wrong results the moment a season has more than one torneo (every season does).

**`orden` is now fully recomputable — and it MUST stay that way.** Since fecha identity moved to `fecha_id` (stable, never reassigned), `orden` is no longer identity: `Calendario\FechaRepository::recalculateOrden()` recomputes `orden` and `numero_en_torneo` for the WHOLE season from scratch on every seed run (1..N by `play_date` ascending, tie-broken by `id`). This is exactly what keeps a late-arriving or postponed fecha correctly positioned without ever renumbering a foreign key.

> **Invariant a future slice must not break:** anything that needs to remember "which fecha" — most notably slice 2's ocupaciones, which anchor the "minimum of 3 resolved fechas" business rule to a starting point — **MUST persist `fecha_id`, and MUST NEVER persist `orden`.** `orden` can and will change value under an unchanged `fecha_id` the next time the calendar reflows (a late Clasificacion fecha loaded after Apertura was already seeded, or a postponement). `FechaRepository::countResolvedFechasSince(int $seasonId, int $fechaId)` exists specifically so callers never touch `orden` directly: it takes a `fecha_id`, resolves that fecha's current `orden` internally, and counts from there — fresh, on every call.

## `estado_origen`: why a reseed (or a postponement) can never overwrite a human decision

`cambios_fecha.estado` can be one of `programada`, `jugada`, `dirimida`, or `suspendida`. `Calendario\EstadoDeriver` only ever derives the first two, and that is by design rather than a missing feature:

- **`dirimida`** — a committee ruling (typically 3-0 for a no-show) is loaded into SportsPress as a normal result, because the standings are computed from the events. So the fecha derives to `jugada`, and since the counter matches `estado IN ('jugada','dirimida')`, it is counted correctly with nobody marking anything. The label is informational; the counter does not depend on it.
- **`suspendida`** — a postponement is observable, but from the repository rather than the deriver: the comision edits the date of every partido of the jornada, so `upsertFecha()` sees the same `match_id`s on a later `play_date` and bumps `veces_postergada`. Meanwhile the fecha derives to `programada`, which is the right answer for the counter — a postponed fecha must not count until it resolves.

`estado_origen` (`derivado` | `manual`) tracks who last decided the value. `Calendario\FechaRepository::upsertFecha()` — which the nightly/on-demand reseed pipeline calls, and which is also what detects and applies a postponement — only touches `estado` when the existing row's `estado_origen` is still `derivado`. Once a human calls `setEstadoManual()`, that decision is sticky forever until another human changes it again; a reseed (or a postponement moving the fecha to a new day) can update the fecha's partidos, `play_date`, `veces_postergada`, `orden` and `numero_en_torneo`, but it will never silently flip a committee's ruling back to `programada`.

## Captaincy and authorization (slice 1)

Slice 1 adds the domain model and authorization logic a captain needs before this plugin can accept a request FROM one. It ships no REST routes or UI — see "Scope of this slice" below — only the pieces later slices will call.

### Where the identity comes from: entre-redes-prode's JWT, reused without reusing its code

Players authenticate through the `entre-redes-prode` plugin, which issues a short-lived (900s) RS256 access token (`Auth\JwtService`). This plugin's `Auth\TokenVerifier` verifies that same token — signature, expiry, and its `typ` claim — **without depending on a single class from entre-redes-prode**. The only things the two plugins share are:

- **A value**: the RS256 public key, stored in plain text at `wp_options['prode_rsa_public_key']` (the key id is `wp_options['prode_rsa_key_id']`). There is no JWKS endpoint — the key is read directly from the option and injected into `TokenVerifier`'s constructor.
- **A table**: see "Why this plugin reads `prode_users.session_version`" below.

`TokenVerifier::verify()` deliberately does **not** validate the `iss` or `aud` claims. Replicating prode's audience check would require reading `prode_settings.tenant_id` — a strictly bigger cross-plugin coupling than the one already accepted for revocation. This is a conscious, revisable decision for a single-tenant deployment, documented in the class's own docblock, not an oversight.

### Why this plugin reads `prode_users.session_version`

A signature and an `exp` check alone cannot see a revoked session — see `Auth\ProdeSessionGateway`'s class docblock for why `session_version` closes that gap, and why it fails closed on a missing user or a missing table.

### The `capitan` you see in `/jugadores` is not this

The `sp_position` taxonomy term is a player tag, unrelated to authorization — see `Capitania\CapitanRepository`'s class docblock ("NOTE ON THE `capitan` TAXONOMY TERM") for the full distinction and why it must stay that way.

### One vigent captain per team and season — defended in code, not by a UNIQUE key

See `Migrations\InitialSchema::sqlCambiosCapitan()`'s docblock for why this cannot be a `UNIQUE` key and how `CapitanRepository::designateCapitan()` defends the invariant instead.

### Putting it together: `Capitania\CapitanAuthorizer`

`CapitanAuthorizer::authorize( $jwt, $seasonId, $teamId, $now )` is the single entry point later slices should call — see that class's docblock for why it composes `TokenVerifier`, `ProdeSessionGateway`, and `CapitanRepository` in that order, and for the generic-message-per-distinct-exception-type contract its rejections follow.

## Plazas and ocupaciones (slice 2)

Slice 2 models the domain data behind a player change: which plaza belongs to whom, and who is currently occupying it. It ships no dictamen engine, no REST routes, no admin UI, and no backfill — see "Scope of this slice" below.

### The aggregate is the plaza, not the solicitud

A team is a set of 11 `cambios_plaza` rows, ALL of them titular plazas — there is no `tipo` column splitting them into "campo" and "suplente" plazas. An earlier version of this schema did carry such a column; the process owner corrected it: "9 on the pitch, 2 on the bench" describes an INSTANT during a match (which rotates constantly via unlimited in-match substitutions), never a fixed property of a plaza, so the column was removed before the table's backfill ever ran in production. Each plaza has a **permanent titular** (`titular_player_id`, never reassigned) and a **puntaje ceiling snapshotted at conformación** (`puntaje_techo`, which never moves for the plaza's lifetime — only who occupies it changes). See `Migrations\InitialSchema::sqlCambiosPlaza()`'s docblock for the column-level detail.

### The cadena de ocupaciones

Every change of occupant on a plaza — titular → suplente, suplente → another suplente, or the titular returning — is the SAME operation: close the currently vigent `cambios_ocupacion` link and open a new one. This is what `Plazas\PlazaRepository::succeedOcupacion()` and `::closeOcupacionByRegresoTitular()` do, and it is why "el cambio de cambio" needs no special-case branch anywhere in this model — see `Plazas\CadenaResolver`'s class docblock for the full reasoning. `PlazaRepository::openPlaza()` creates a plaza and its genesis (titular) ocupación together, in one transaction — this is the "conformación" moment.

At most one ocupación per plaza is ever vigent (`fecha_hasta_id IS NULL`) — defended the same way `cambios_capitan`'s "one vigent captain" rule is: a code-level guard inside a transaction, asserted as a PROPERTY in tests rather than a DB constraint (see `Migrations\InitialSchema::sqlCambiosOcupacion()`'s docblock for why). The guard is a compare-and-swap: closing an ocupación requires `fecha_hasta_id IS NULL` in the UPDATE's WHERE clause and requires exactly 1 affected row, so two concurrent requests racing to close the SAME ocupación can never both succeed — the loser gets `PlazaPersistenceException` and its transaction rolls back. `findOcupacionVigente()` never trusts `LIMIT 1` to paper over a corrupted state either: if it ever finds more than one vigent row for a plaza, it throws, because a business decision must never depend on row storage order.

`cambios_ocupacion.es_genesis` marks the plaza's FOUNDING link only — it does not mean "is this player the titular" (a returning titular's link is not flagged again). The only source of truth for "who is the titular of this plaza" is `cambios_plaza.titular_player_id`, which never changes.

### The plaza's liberation moment is derived, never stored

The 3-fecha minimum (counted in RESOLVED fechas via `Calendario\FechaRepository::countResolvedFechasSince()`, never in matches played) is a **piso**, not a vencimiento: once cleared, an ocupación renews by silence, with no maximum duration, until something explicitly closes it. A plaza has exactly ONE liberation fecha, governing both when the titular may return and when EVERY ex-occupant who left 'trunca' (before clearing the minimo) unblocks — all of them, together. `Plazas\CadenaResolver::isPlazaLiberable()` recomputes this fresh from the vigent link on every call; storing it would mean rewriting it on every new link, which is exactly the stale-cache bug the derivation avoids. See that class's docblock for the full argument.

### Puntaje: an integer, never a float

`Plazas\Puntaje` is the only representation of a puntaje the rest of the feature should hold — 9 discrete values (1..5 in 0.5 steps), stored and compared as an integer ×2 (2..10), never as a float. See that class's docblock for the concrete parsing bug (a comma-decimal string silently truncated by a naive `(float)` cast) and the comparison risk this representation avoids. The "regla del 2,5" collapses into one expression, `techoEfectivo()`: `MAX(puntaje_techo, 5)` in half-points.

### Explicitly out of scope

- **The dictamen engine** — deciding whether a solicitud de cambio is approved — is slice 3's pure function, built on top of `CadenaResolver`'s derivations. This slice only models the data and the chain; it makes no approval decisions.
- **Backfilling the in-progress season's real occupancy** — who occupies which plaza today lived only in the process owner's spreadsheet, not in any system this plugin could read. Closed by the CSV importer — see "Importing plazas from a CSV backfill" below.

### Conditions of entry for slice 3 — known gaps, deliberately not closed here

The following are real gaps this slice leaves open. None of them is implemented — they are recorded here so slice 3 starts from an accurate picture instead of assuming more safety exists than actually does:

1. **No validation that `fecha_desde_id` / `fecha_hasta_id` exist in `cambios_fecha`, or belong to the same season.** These are LOGICAL foreign keys only (see `Migrations\InitialSchema::sqlCambiosOcupacion()`'s docblock) — nothing in `PlazaRepository` checks that the fecha id a caller passes actually exists, let alone that it belongs to `cambios_plaza.season_id`. A caller passing a stale, deleted, or cross-season fecha id is accepted silently today.
2. **No observability.** There is not a single `error_log()` call or WordPress action hook anywhere in this plugin. A `PlazaPersistenceException`, a `RuntimeException` from a broken invariant, or a fail-closed `FechaCountUnavailableException` today leaves no trace anywhere except the caller's own exception handling (or lack of it) — a production failure is invisible until someone notices the business symptom.
3. **No correction primitive.** There is no way to undo or repair a wrongly-loaded plaza or ocupación — no "delete this link", no "reopen this plaza" — short of a direct SQL fix. This is acceptable ONLY because no UI exists yet to make the mistake in the first place; it becomes a blocker the moment slice 3 (or any admin screen) lets a human load real data.
4. **No check that the tables are actually InnoDB.** Every invariant this slice defends inside a transaction (at most one vigent ocupación, atomic close-then-insert) silently depends on `ENGINE=InnoDB` actually taking effect. If a hosting provider's `dbDelta()` run substitutes a non-transactional engine (some managed MySQL configurations do this transparently), `START TRANSACTION` / `ROLLBACK` become no-ops and every invariant in this README degrades without any error ever being raised.

## Importing plazas from the official titulares list

`cambios_plaza` is empty until this runs. Nothing else in this plugin opens a plaza on its own — no REST route, no admin screen, no cron ever calls `PlazaRepository::openPlaza()` / `::openPlazaWithinTransaction()` — so until the backfill happens, every captain's own plantel screen in the app is legitimately empty (`lib/screens/cambios/cambios_plantel_screen.dart` has its own explicit empty state for exactly this reason, not a bug).

`tools/importar-titulares.php` reads a plain CSV of already-resolved ids and hands it to `Plazas\Alta\TitularesListImporter` — see that class's own docblock for the full model. It replaces `tools/importar-eleccion.php` and the whole `Plazas\Eleccion\` namespace (removed entirely, together with the `phpoffice/phpspreadsheet` dependency): parsing the March 2026 election `.xlsx` was the wrong design from the start. That spreadsheet was a one-off cross-reference the process owner used to settle who each team's official titulares are — never a data source this plugin should keep parsing on an ongoing basis. That cross-reference is finished; its OUTPUT is the plain CSV this importer reads, already resolved to WordPress ids, with no name matching left to do.

### The CSV

Four required columns, matched case-insensitively and in any order: `team_id` (the WordPress `sp_team` post id), `titular_player_id` (the WordPress `sp_player` post id), `puntaje_techo` (one of the 9 valid puntajes — see `Plazas\Puntaje`), and `es_capitan` (literally `1` or `0`). An `equipo` column may also be present — it is read ONLY to make error messages legible, never to resolve or cross-check anything; `team_id` is the sole identifier this import trusts. See `tools/examples/titulares-oficiales.ejemplo.csv` for the shape (invented ids — the real season-2026 list is operator data, never committed).

Neither a `team_id` nor a `titular_player_id` that does not resolve is ever created: both a team and a player already exist by the time this importer runs (a new player is registered by hand, at inscription, before the election this data comes from) — see `TitularesListImporter`'s own class docblock for why copying SportsPress's own player importer's "create if missing" behavior here would be worse than simply failing.

### How to run it

```bash
php tools/importar-titulares.php --csv=<archivo.csv> --season-id=<id> --fecha-desde-id=<id> [--apply] [--apply-capitanes]
```

Dry-run is the default: without `--apply` (plazas) or `--apply-capitanes` (captaincy), the command only validates and prints the plan — nothing is written. The two writes are independent — either can run without the other. `php tools/importar-titulares.php --help` prints the full contract and needs neither WordPress nor a database connection.

### The safety properties, and why each exists

- **The whole CSV is validated before a single row is written.** `TitularesListImporter::planificar()` performs only reads; `aplicarPlazas()` / `aplicarCapitanes()` both refuse outright when the plan has any error, and every plaza opened is written as ONE atomic transaction. A partially imported roster is worse than none: the captain screens would show some teams a plausible, wrong squad, and nobody would notice until a captain requested a change against a plaza that does not exist.
- **It is idempotent on `(season_id, team_id, titular_player_id)`**, same key and same reasoning as the importer it replaces — retrying `--apply` after a successful import opens nothing new.

### What this importer does not treat as an error

A team whose row count is not exactly 11 is a WARNING, never an error — this importer is also meant to re-run mid-season, when a real squad can legitimately differ from exactly 11 rows (an injury replacement already resolved, a plaza not yet filled); refusing the whole load over that would defeat the backfill's own purpose.

## The dictamen engine (slice 3)

Slice 3 is the pure-function decision layer on top of slice 2's data model: given a `Dictamen\SolicitudDeCambio` and a fully-assembled `Dictamen\DictamenContext` (zero DB access, zero clock — see that class's docblock), `Dictamen\DictamenEngine` runs every `Dictamen\Regla` and joins every `Dictamen\Motivo` they report into one `Dictamen\Dictamen`. It ships no REST routes, no admin UI, no context assembly from the real database — see "Explicitly out of scope" below.

### A dictamen is not an approval

`Dictamen::procede()` is exactly "zero motivos" — never a stand-in for `aprobado()`. Per the reglamento, the subcomisión is never obligated to provide a reemplazo, and the process owner approves ALWAYS, even when a solicitud clears every rule. See `DictamenEngine`'s class docblock for the full reasoning.

### Never short-circuits, never lets one rule's crash erase another's motivo

`DictamenEngine::evaluate()` runs all seven rules unconditionally and collects every motivo — a captain reading a rejected solicitud sees every reason at once. A `Regla` that throws (corrupted business data it cannot evaluate at all, e.g. a `puntaje_techo` outside `Plazas\Puntaje`'s valid range) is caught per-rule and turned into its own fail-closed motivo (`error_al_evaluar_regla`, tagged with the offending rule's class name) — it does not abort the loop and does not discard the motivos other rules already found.

### The ruleset has exactly one source of truth: `DictamenEngineFactory`

`Dictamen\DictamenEngineFactory::create()` (and `::reglas()`) is the ONLY place, production or test, that lists the eight rules. `DictamenEngineFactoryTest` locks that list down; every other test builds its engine through the factory instead of keeping a parallel copy — the same shape of bug as `Calendario\EstadoDeriver` forgetting a state, closed by having exactly one enumeration to forget.

### The eight rules

`Reglas\PuntajeDentroDelTecho`, `Reglas\EntranteNoBloqueado`, `Reglas\EntranteDisponible`, `Reglas\EntranteNoEsElSaliente`, `Reglas\SolicitudEnPlazo`, `Reglas\PlazaConOcupacionVigente`, `Reglas\PlazaNoCerrada`, `Reglas\RegresoSoloConMinimoCumplido` — each a pure function of `DictamenContext`, independent of the others (see `Regla`'s class docblock). Three are worth calling out:

- **`PuntajeDentroDelTecho`** treats a missing `entrantePuntaje` differently by `tipo`: null is the type's own guarantee for a `regreso` (no objection), but for a `sustitucion` it is an unresolved external lookup and is reported as its own motivo (`entrante_puntaje_indeterminado`) rather than silently read as "no objection" — see that class's docblock.
- **`EntranteNoBloqueado`** takes CC5b's still-unconfirmed policy (`BloqueoReemplazoPolicy::topeTresFechas()` vs `::hastaLiberacionDePlaza()`) as a constructor parameter, defaulting to the less severe reading. The motivo it produces carries `datos()['politica']` naming which reading fired, so the process owner's eventual answer can be checked against what was actually applied to past solicitudes.
- **`PlazaNoCerrada`** (slice 4c) rejects a solicitud targeting a plaza the operator has `closePlaza()`d — otherwise a solicitud submitted before the closure could still get published on the following Friday against a plaza the operator explicitly declared inert. See "Correction primitives" below for the matching write-side guard in `PlazaRepository` itself.

### Explicitly out of scope

- **Assembling `DictamenContext` from the real database** (`PlazaRepository`, `FechaRepository`, `Calendario\Settings` + `PlazosCalculator`) is slice 4's job — every test in this slice hands the context a hand-built fixture.
- **REST routes, admin UI, wiring a caller.** This slice is domain logic only.

### Contracts for slice 4 — written here, not yet implemented

3. **When CC5b is resolved, remove `BloqueoReemplazoPolicy`'s default** (`EntranteNoBloqueado`'s constructor currently defaults to `topeTresFechas()` when no policy is injected) — whoever wires `DictamenEngineFactory::create()` for real must pass the confirmed policy explicitly, so a future ambiguity cannot silently fall back to a guess again.

Points 1 and 2 above are implemented — see "Assembling the dictamen context (slice 4b)" below.

## Assembling the dictamen context (slice 4b)

Slice 4b builds `Dictamen\DictamenContext` from the real database and wires the complete evaluation pipeline — the piece slice 3 explicitly left out. It ships no REST routes, no admin UI, no cron (see "Scope of this slice" below); exposing this is the next slice's job.

### Every context-filling query throws on failure, never returns `[]`

`Plazas\PlazaRepository::listOcupacionesVigentesDeJugador()` and `::listPlazasConCierreTruncadoDeJugador()` feed `DictamenContext::entranteOcupacionesEnOtrasPlazas()` / `::entrantePlazasConCierreTruncado()`. An empty result from either reads to `Reglas\EntranteDisponible` / `Reglas\EntranteNoBloqueado` as "confirmado, sin conflicto" — so both new methods (and their internal chain fetch) distinguish a genuine empty result from a wpdb-level query failure (`$wpdb->get_results()` returning `null`, or leaving `$wpdb->last_error` non-empty) and throw — logging `lectura.fallida` first — rather than let a broken query silently approve a solicitud. `Dictamen\DictamenContextAssembler::assemble()` extends the same discipline to the plaza and fecha lookups themselves: neither existing is a hard requirement, never a "no conflict".

The plaza's OWN ocupaciones chain (`PlazaRepository::listOcupaciones()`, unchanged since slice 2) is deliberately NOT covered by this same discipline — an empty chain already fails closed by construction of the existing ruleset (`Reglas\PlazaConOcupacionVigente` rejects a plaza with no vigent link). See `DictamenContextAssembler`'s class docblock for the full argument.

### The entrante's puntaje: two keys, never a silent zero

The entrante's puntaje lives in `sp_metrics` postmeta on the player's post, read via raw `$wpdb` (never `get_post_meta()`, consistent with every other query in this feature). `entre-redes-api.php` itself reads this same value under two different keys depending on the endpoint (`'puntaje'` and `'Puntaje'`) — `DictamenContextAssembler::resolveEntrantePuntaje()` tries both. A puntaje of `0` is never a valid torneo score (1..5 in 0.5 steps), so a missing value is never defaulted to it: when neither key resolves to a usable value, this returns `null` — the same legitimate missing-data signal `Reglas\PuntajeDentroDelTecho` already converts into its own blocking motivo — and logs `entrante.puntaje_no_encontrado` for an operator to fix the data. A value that IS present but is not one of the 9 valid puntajes is a different problem (corrupted data, not a gap): `Plazas\Puntaje::fromDecimal()` throws for it, uncaught.

### The sanity cap on the injected resolved-fechas counter

`Plazas\CadenaResolver`'s own class docblock says an INFLATED resolved-fechas count is not something that class can detect — it has no season total to compare against, and bounding it is "the responsibility of whoever provides the callable". `DictamenContextAssembler` is that provider: it wraps `Calendario\FechaRepository::countResolvedFechasSince()` with the season's own total of resolved fechas and throws `Plazas\Exception\FechaCountUnavailableException` the instant a count exceeds that total — an answer that is, by construction, impossible. `CadenaResolver` and `Reglas\EntranteNoBloqueado` already translate that exception into a fail-closed answer.

### `Dictamen\DictamenPipeline` — the one thing a future caller needs to call

`DictamenPipeline::evaluate( SolicitudDeCambio $solicitud ): Dictamen` composes `DictamenContextAssembler::assemble()` and `DictamenEngineFactory::create()->evaluate()` — using `DictamenEngineFactory` is meant to be the path of least resistance, not a discipline someone has to remember. It also catches `\Throwable`, logs `dictamen.fallido` with the solicitud's own identifiers (season, team, plaza, fecha, tipo), and re-throws — so "the subcomisión rejected it" and "the pipeline crashed" are never indistinguishable to whoever reads the log, even though no REST layer exists yet to decide the HTTP answer.

### Explicitly out of scope

- **REST routes, admin UI, cron.** This slice is domain wiring only — see "Scope of this slice" below.
- **CC5b's confirmed policy** — see point 3 above, still open.

## Guardrails (slice 4)

Before slice 4, this plugin had no `error_log()` call and no hook anywhere — a captain reporting "I asked for a change and nothing happened" had nothing to look at. This slice adds the observability and defensive checks a real endpoint needs before it can go live; it still ships no REST routes, no admin UI, no cron (see "Scope of this slice" below) — it stays domain infrastructure.

### `Observability\EventLog` — the one channel every write and every failure speaks through

`EventLog` is a plain interface (`record(string $evento, array $contexto): void`). `WpEventLog` (production) always fires `do_action('entre_redes_cambios_event', $evento, $contexto)` and additionally writes to `error_log()` — and ONLY `error_log()`, no custom file — when `WP_DEBUG` is active. `InMemoryEventLog` (tests) keeps every event in order, so a test can assert not just that an event fired but that it fired BEFORE an exception propagated.

`Plazas\PlazaRepository` and `Capitania\CapitanRepository` both take an `EventLog` as a MANDATORY constructor argument — there is deliberately no null-object default. A default that silently swallowed events would reproduce, in code, the exact silent-failure pattern this slice exists to end. Every successful write that matters to an operator (`plaza.abierta`, `ocupacion.sucedida`, `ocupacion.regreso_titular`, `ocupacion.deshecha`, `plaza.cerrada`, `capitan.designado`, `capitan.revocado`) is recorded AFTER its `COMMIT`; every failure (`escritura.fallida`) is recorded BEFORE the exception is thrown, with the ids needed to diagnose it (season, team, plaza, ocupación, player, fecha, and `$wpdb->last_error` when applicable).

**`record()` itself never propagates (slice 4c).** `WpEventLog::record()` wraps its entire body in `try`/`catch (\Throwable)`: a listener some OTHER plugin registered on `entre_redes_cambios_event` throwing must never take down the caller — which, for every write in this plugin, is AFTER that write already committed — and must never REPLACE the original exception a `catch` block was trying to log (see `Dictamen\DictamenPipeline::evaluate()`). A failure here falls back to a single `error_log()` line and nothing more; logging is best-effort on purpose, because a log that can abort the operation it merely records is worse than no log at all.

**Every COMMIT and ROLLBACK is checked, not just START TRANSACTION (slice 4c).** `Support\OpensTransactions::commitTransaction()` / `::rollbackTransaction()` extend the same discipline `beginTransaction()` already applied to `START TRANSACTION`: `$wpdb->query()` returns `false` on failure for all three statements, and silently ignoring that let a failed COMMIT report success, or a failed ROLLBACK report "abortado", for writes whose real fate was unknown. A failed COMMIT now logs `transaccion.commit_fallido` and throws — the caller can never believe an unconfirmed write went through. A failed ROLLBACK now logs `transaccion.rollback_fallido` (the worst event this plugin can emit — it means the database state is genuinely UNKNOWN, not "rolled back") and throws `Support\Exception\InconsistentStateException`, chaining whatever exception triggered the rollback attempt as `previous` so the original cause is never lost.

### Fecha id validation — `PlazaRepository`

`fecha_desde_id` / `fecha_hasta_id` are LOGICAL foreign keys into `cambios_fecha` with no DB-level constraint (same "SQLite shim drops every KEY" story as everywhere else in this schema — see `InitialSchema`'s docblock). `openPlaza()`, `succeedOcupacion()` and `closeOcupacionByRegresoTitular()` now validate — via `assertFechaExistsInSeason()` — that the id exists AND belongs to the same season as the plaza, before writing anything. An id typo or one copied from the wrong season now fails loud, at the write, instead of surfacing much later inside `Calendario\FechaRepository::countResolvedFechasSince()`.

### Correction primitives — `PlazaRepository::undoLastOcupacion()` / `::closePlaza()`

Before this slice, fixing a plaza opened with the wrong titular, or an ocupación succeeded by mistake, meant raw SQL against production — run by a parents' committee, not a developer. `undoLastOcupacion()` deletes the last link of a plaza's chain and reopens the one before it (refusing on a single-link/genesis-only chain — use `closePlaza()` for that case instead). `closePlaza()` marks a plaza's `closed_at` for one opened entirely by mistake. Both are explicitly CORRECTION tools, not part of the normal solicitud/regreso flow — nothing in `Dictamen\DictamenEngine` or `Plazas\CadenaResolver` ever calls them. **Neither method authorizes its caller** — both perform no role or ownership check of their own; a future REST wrapper must verify the caller's role before invoking them.

A closed plaza is defended on BOTH sides, not just the read side: `Dictamen\Reglas\PlazaNoCerrada` (slice 4c) rejects a solicitud against it, and `PlazaRepository::succeedOcupacion()` / `::closeOcupacionByRegresoTitular()` (and their `*WithinTransaction` twins) refuse to write over one via `assertPlazaNotClosed()` — a correction that only the dictamen ruleset respected would stop correcting anything the moment a stale or previously-approved solicitud reached the write path directly.

### InnoDB storage-engine check — `MigrationRunner`

Every invariant this plugin defends ("at most one vigent ocupación/captain") depends on `START TRANSACTION` / `COMMIT` / `ROLLBACK` being real, which requires every `cambios_` table to actually be InnoDB. MySQL accepts `ENGINE=InnoDB` and silently substitutes another engine on some hosts, WARNING but not failing. `MigrationRunner::run()` now checks each table's real engine via `information_schema.TABLES` after migrations, logs `motor.no_innodb` and shows an `admin_notice` if any table isn't InnoDB — tolerantly: if the query itself is unavailable (e.g. the SQLite test shim has no `information_schema`), it is treated as "could not check", never as "found a violation".

## Solicitudes de cambio: ciclo de vida (slice 4c)

Slice 4c turns the SOLICITUD DE CAMBIO into a persisted entity with its own lifecycle (`Solicitudes\SolicitudRepository` + `Solicitudes\EstadoSolicitud`) — everything before this slice only had `Dictamen\SolicitudDeCambio`, a pure, ephemeral input DTO the engine evaluates and never itself stores. It ships no REST routes, no admin UI, no cron — see "Scope of this slice" below; a REST wrapper is a later slice's job, and every dangerous method here says so explicitly in its own docblock.

### The estado machine

`estado` moves `pendiente` → (`aprobada` | `rechazada` | `anulada`), and `aprobada` → (`publicada` | `rechazada` | `anulada`). `publicada`, `rechazada` and `anulada` are terminal — nothing transitions out of any of them. `EstadoSolicitud` is the single source of truth for this graph, defended in code (never a DB constraint) for the same reason as `Calendario\FechaRepository::VALID_ESTADOS` and `Plazas\PlazaRepository::VALID_CERRADA_POR` — see that class's own docblock.

### Aprobar is not publicar

The real operating calendar has the process owner reviewing and approving solicitudes AS THEY ARRIVE, Wednesday through Thursday, but the change only becomes OFFICIAL at Friday's lote announcement. `aprobar()` NEVER touches `Plazas\PlazaRepository` — it only flips `estado`; no ocupación opens or closes. Until the lote runs, an `aprobada` solicitud is still an INTENTION, revocable via `rechazar()` / `anular()`. `publicarLote()` is the ONLY method that turns an approved intention into a real change of occupant.

### The Friday lote is atomic, and it re-evaluates before applying

`publicarLote()` re-runs `DictamenPipeline::evaluate()` for every solicitud in the lote, fresh, against the CURRENT database, right before applying anything — the dictamen a solicitud was created with is a snapshot that can go stale between Wednesday and Friday (a fecha passed, the entrante got occupied elsewhere, someone else already succeeded the same plaza). If any one of them no longer `procede()`, is not currently `aprobada`, or its `dictamen_original` cannot even be parsed, the WHOLE lote aborts — nothing in it is applied, and the caller gets back which solicitud caused it (`culprit_id`, a plain field alongside the human-readable `motivo`) — because a lote applied halfway would leave nobody able to tell which of it actually went through. Every solicitud in one call to `publicarLote()` must belong to the SAME `season_id`; a lote mixing seasons aborts too.

Every write below the pre-flight phase runs inside ONE database transaction, opened, committed and rolled back through `Support\OpensTransactions` — see "Guardrails" above for why every one of those three steps is checked, not assumed. `PlazaRepository::succeedOcupacionWithinTransaction()` / `::closeOcupacionByRegresoTitularWithinTransaction()` exist for exactly this caller: same validation and write as the normal `succeedOcupacion()` / `closeOcupacionByRegresoTitular()`, but against the ambient transaction this class already opened, leaving the EventLog record and the `estado` transition to `publicarLote()` itself once the whole lote's COMMIT has actually succeeded.

The `cambios_ocupacion` id each solicitud's write produced is persisted back onto the solicitud row (`ocupacion_id`) at the moment it publishes — so undoing a wrongly-published lote never again requires cross-referencing the EventLog by `plaza_id` and timestamp by hand.

### None of this authorizes the caller

`publicarLote()`, `aprobar()` / `rechazar()` / `anular()` are among the most dangerous entry points this plugin exposes — they write real occupancy changes over real rosters. None of them perform a role or ownership check of their own; a future REST wrapper MUST verify the caller's role (and, for `aprobar`/`rechazar`/`anular`, that the solicitud belongs to a team they may act on) BEFORE invoking any of them.

## Parent priority: a policy that is built, tested, and deliberately OFF

The tournament has always distinguished parents of the school from historical
guests, and has always preferred parents — as a soft rule nobody enforced. It is
now a setting, `prioridad_padres_activa`, read per request by
`Calendario\Settings::prioridadPadresActiva()` and **seeded off**
(`Migrations\InitialSchema::SEED_DEFAULTS`), which reproduces today's behaviour
exactly.

It is a plain `bool`, not a policy object — unlike `Dictamen\
BloqueoReemplazoPolicy`, which is an object because CC5b has two distinct
readings to choose between. This setting has one question and two answers, so
a bool says everything there is to say. `Plugin::boot()` threads it explicitly
through `Dictamen\DictamenPipeline` and `Dictamen\DictamenEngineFactory::create()`
into `Dictamen\Reglas\PrioridadDePadresRespetada`'s constructor; turning it on
must stay one visible value rather than a default buried somewhere.

When it is ON, `Dictamen\Reglas\PrioridadDePadresRespetada` rejects a non-parent
entrante if, and only if, at least one VIABLE parent exists for that plaza.

### Why this is a restriction, not a sort order

An ordering would be an app concern. This is not: one candidate's eligibility
depends on the rest of the pool, so the engine itself has to know whether any
parent fits that plaza. `DictamenContextAssembler` loads that count only when the
policy is on AND the entrante is not a parent — with the policy off, the feature
costs zero extra queries.

### "Viable" means available, not merely well-rated

`Plazas\CandidatosResolver` counts a parent only when their puntaje fits the
plaza's ceiling AND they are actually free: not blocked by a truncated
ocupación, not holding another plaza. A parent with the right rating but blocked
helps nobody, and counting him would bar the non-parent WITHOUT letting the
parent in — the team would be unable to change anyone at all. The rule exists to
prefer parents, not to trap teams.

`CandidatosResolver` is also the single source of "who may fill this plaza",
shared by the rule and by the endpoint that feeds the captain's screen. Had the
app computed eligibility on its own, it would eventually disagree with the
engine, and a captain would pick someone the screen showed as valid only for the
system to reject it. The backend decides; the app displays.

### How a parent is recognised, and why the blank counts as "not a parent"

`caracter` is a dedicated ACF field on the player (`acf.caracter` on
`wp-json/wp/v2/sp_player/{id}`), populated deliberately across the whole
roster — a clean, consistent vocabulary, not the free-text mess an older
SportsPress metric field used to be. So a candidate counts as a parent when
the value starts with `padre`, case-insensitively; anything else, blank
included, counts as not a parent. This policy takes something away, and an
ambiguous record must never be the reason someone gains an advantage.

A consistent vocabulary is not the same thing as correct data, and the
difference is not hypothetical here — see the next section.

### The data gap that used to gate turning this on — closed

> Verified against live production data (1105 players, `acf.caracter`):
> `Padre Alumno` 570, `Padre Ex-Alumno` 268, `Invitado` 129, empty 114,
> `Personal Colegio` 22, `Socio Fundador` 2. The ACF field is **90%
> populated**, with a clean vocabulary — not the 65%-empty legacy field this
> section used to report.

With the rule as written, empty still means "not a parent" — that has not
changed. But the field it reads from is no longer mostly empty: turning this
policy ON today would classify the large majority of the roster correctly,
with only the genuinely unfilled 10% defaulting to "not a parent", exactly as
the policy always intended for an ambiguous record.

**The data gap that used to block enabling this policy is closed.** Whether
to turn `prioridad_padres_activa` on is a product decision now, not one
blocked by missing data.

### But the values are not all CORRECT, and one category is known bad

Of the 24 players carrying `Personal Colegio` or `Socio Fundador`, only three
are registered in the current season at all — the other 21 are in the
"no inscriptos" pseudo-team, which does NOT carry the season taxonomy term
and therefore never reaches the candidate pool (see the pool's own note
below). The process owner reviewed those three by name, and **all three are
mislabelled**: two are padres de alumno, one is an invitado. None is school
staff.

So every in-pool record of that category is wrong. That matters more than the
count suggests, because two of them are padres the rule would currently treat
as NOT padres — penalising the exact people the policy exists to favour. A
rule that classifies people wrongly is worse than no rule, so this is a
correction to make in WordPress before the policy is ever enabled, not
something to special-case in code.

It also says something about the field as a whole: `caracter` being
well-formed does not make it accurate. The 90% figure above measures how much
of it is FILLED. Nobody has yet measured how much of it is RIGHT, and the one
category anyone has audited came back entirely wrong.

### One more unknown in the same neighbourhood

The player also carries an ACF `estado` (`Habilitado` for 588 of 1105, blank
for 517). In the current season's pool, 43 of the 96 players on the waiting
list do NOT have it set. Nothing in this plugin reads that field, so if
`Habilitado` encodes something like medical clearance or a confirmed
registration, the captain's candidate list is currently offering people it
should not. Its meaning is an open question for the process owner — recorded
here rather than guessed at.

## Seeding the calendar in production

Slice 0 shipped `Calendario\SeedTemporadaService` with exactly one caller in the whole codebase: `tools/dry-run-calendario.php`, which runs entirely against the in-memory SQLite test shim and says so in its own header — it can never touch real data. That left `cambios_fecha` permanently empty in production, with nothing to fill it, which in turn blocked `Plazas\Alta\TitularesListImporter` (it needs a `fecha_desde_id` from that table). This closes that gap with two production callers, never by changing the seeder itself.

### `Calendario\Cron\SeedCalendarioCron` — the daily WP-Cron event

Registered on plugin activation (`entre_redes_cambios_seed_calendario_daily`, recurrence `daily`) and cleared on deactivation — an orphaned cron event that outlives the plugin is a bug that only shows up as mystery load months later. `Plugin::boot()` also re-registers it on every request if missing, as a safety net for the "plugin files overwritten without going through WordPress's activate flow" case (mirrors `entre-redes-prode`'s own pattern in that exact spot).

It is bound to `add_action`, never to a SportsPress save hook — see the class's own docblock for why: this codebase already paid for exactly that mistake once (`wpm2_jugador_partido`'s sync bound to `save_post_sp_event`, which fires BEFORE the generic `save_post` where SportsPress writes a match's own metas, so the listener read a half-saved match; no hook priority can fix that, because priority only orders callbacks within one hook, never between two different hooks). A cron reading the already-published `/entre-redes/v1/*` REST API avoids that race entirely.

**The overlap lock.** `FechaRepository::recalculateOrden()` writes `orden` in two passes (it parks every moving row in a disjoint high range first, because `UNIQUE(season_id, orden)` collides transiently — see that method's own docblock), so two seed runs in flight together could interleave into a corrupt ordering. `SeedCalendarioCron` takes an advisory lock (a transient, `cambios_seed_calendario_lock`) before doing anything else: whoever sets it first proceeds, anyone else backs off and records `calendario.seed_bloqueado` instead of racing. The lock carries a 10-minute TTL purely as a safety valve against a run that died without releasing it.

**Failure is loud, not silent.** A network timeout or a malformed API payload throws from inside `PartidosApiClient`'s fetch calls — which all run BEFORE `FechaRepository` is even constructed, so nothing has been written yet — and is caught and recorded as `calendario.seed_fallido` through `Observability\EventLog`, with the underlying error message. A successful run records `calendario.seed_exitoso` with the season id and fecha count. Every one of these codes is greppable in the `entre_redes_cambios_event` action or the PHP error log (see `Observability\WpEventLog`).

**WP-Cron is traffic-triggered, not a real scheduler** — a quiet site can run this hours late, which is fine here (the committee updates the fixture at most a few times a week). That is exactly why the manual trigger below also exists.

**The known limitation it inherits.** The default fetcher is built on `PartidosApiClient`, whose own docblock already says `/partidos` cannot distinguish a genuine 0-0 from a published-but-resultless partido, and that "the DEFINITIVE production fetcher should NOT be this REST client." This slice wires it up anyway — it is the only fetcher this codebase has that does not depend on a live `sp_results` integration — not because that limitation is resolved. A future slice that replaces it only needs to change one factory method; nothing else here would need to change.

### `tools/sembrar-calendario.php` — the manual trigger

Bootstraps a real WordPress install (same `wp-load.php` upward search as `tools/importar-titulares.php`) and runs the exact same `SeedCalendarioCron` pipeline against the real `$wpdb` — sharing the WP-Cron event's own overlap lock, so a manual run and the cron can never race each other either.

**Dry-run is the default; `--apply` is required to write.** Without `--apply`, this script fetches nothing and writes nothing — it reads and reports the calendar EXACTLY as already persisted (whatever the cron, or a previous `--apply`, already wrote), through the same table-plus-validations report `tools/dry-run-calendario.php` uses (see `Calendario\Cli\CalendarioReport` below). It deliberately does not attempt to preview a hypothetical fresh fetch: `SeedTemporadaService::seed()` has no plan-only mode, and wrapping it in a transaction to roll back afterwards is not a safe substitute for one — `recalculateOrden()` already opens and commits its OWN inner transaction, so an outer rollback would either be refused outright (the SQLite shim) or, worse, silently commit everything early on real MySQL, which would make "dry run" secretly write. `--apply` is therefore the only mode that ever calls the fetcher. `php tools/sembrar-calendario.php --help` prints the full contract and needs neither WordPress nor a database.

**The one thing that must never be confused: which script can touch real data.** `tools/dry-run-calendario.php` runs against the in-memory SQLite shim and can never write to a real install, full stop — its own header says so, and so does `tools/sembrar-calendario.php`'s. Keep both scripts; they answer different questions — one proves the pipeline is correct against a known, frozen fixture, the other operates the real calendar.

### `Calendario\Cli\CalendarioReport` — one presentation, two callers

Both scripts print the exact same table (orden, play_date, torneo_label, numero_en_torneo, partido count, estado, veces_postergada) and the exact same GENERIC validations — orden continuity, numero_en_torneo resets, the first fecha being Clasificacion, estado matching its partidos, and `countResolvedFechasSince()` agreeing with the derived jugada count — because these hold true at ANY point in a season, loaded or not. `tools/dry-run-calendario.php` additionally pins its OWN exact-count assertions (23 fechas, 15 partidos each, the 5/9/9 phase split) on top of that shared report, because those are regression pins against ONE specific, frozen, already-verified fixture — meaningless against a real season still being loaded week by week, so they stay local to that script.

## Scope of this slice (slice 0)

This is a "pure function, zero UI" slice: `Plugin::boot()` intentionally registers no REST routes, no admin screens, and no cron jobs. It only runs migrations on activation. The calendar admin screen, the solicitud/regreso REST endpoints, and the seeding cron are later slices, built on top of the domain logic here once it is validated.

Slice 1 (captaincy and authorization, above) keeps the same discipline: no REST routes, no admin UI, no cron. It is domain logic only, ready for the slices that will actually expose it.

Slice 2 (plazas and ocupaciones, above) keeps it too: no REST routes, no admin UI, no cron, no dictamen engine, no backfill. It is domain logic only — the data model and the derivations a later slice's dictamen engine and endpoints will call.

Slice 4b (assembling the dictamen context, above) keeps it too: no REST routes, no admin UI, no cron. `Dictamen\DictamenPipeline` is ready for a future endpoint to call, but nothing calls it yet.

Slice 4c (solicitudes de cambio, above) keeps it too: no REST routes, no admin UI, no cron. `Solicitudes\SolicitudRepository` is ready for a future endpoint to call, but — see that section's "None of this authorizes the caller" — that future endpoint is also where role/ownership authorization must live; nothing in this slice provides it.
