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

## Table structure

The plugin creates 6 custom tables prefixed with `{wp_prefix}cambios_`:

| Table | Purpose |
|-------|---------|
| `cambios_fecha` | One row per jornada of the ENTIRE season (Apertura + Clausura together) — never per-zone |
| `cambios_fecha_partido` | Bridge to the `sp_event` matches that belong to a fecha |
| `cambios_settings` | Operator-configurable parameters: timezone, season_id, and the four plazo offsets |
| `cambios_capitan` | One row per captaincy DESIGNATION (history + current state) — see "Captaincy and authorization" below |
| `cambios_plaza` | One row per PLAZA of a team's roster (the aggregate of the player-change model) — see "Plazas and ocupaciones" below |
| `cambios_ocupacion` | One row per LINK in a plaza's chain of successive occupations — see "Plazas and ocupaciones" below |

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

A team is a set of 11 `cambios_plaza` rows (9 `campo` + 2 `suplente`). Each plaza has a **permanent titular** (`titular_player_id`, never reassigned) and a **puntaje ceiling snapshotted at conformación** (`puntaje_techo`, which never moves for the plaza's lifetime — only who occupies it changes). See `Migrations\InitialSchema::sqlCambiosPlaza()`'s docblock for the column-level detail.

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
- **Backfilling the in-progress season's real occupancy** — who occupies which plaza today lives only in the process owner's spreadsheet, not in any system this plugin can read. No importer is included; a later slice needs that data supplied by the process owner before it can seed real plazas.

### Conditions of entry for slice 3 — known gaps, deliberately not closed here

The following are real gaps this slice leaves open. None of them is implemented — they are recorded here so slice 3 starts from an accurate picture instead of assuming more safety exists than actually does:

1. **No validation that `fecha_desde_id` / `fecha_hasta_id` exist in `cambios_fecha`, or belong to the same season.** These are LOGICAL foreign keys only (see `Migrations\InitialSchema::sqlCambiosOcupacion()`'s docblock) — nothing in `PlazaRepository` checks that the fecha id a caller passes actually exists, let alone that it belongs to `cambios_plaza.season_id`. A caller passing a stale, deleted, or cross-season fecha id is accepted silently today.
2. **No observability.** There is not a single `error_log()` call or WordPress action hook anywhere in this plugin. A `PlazaPersistenceException`, a `RuntimeException` from a broken invariant, or a fail-closed `FechaCountUnavailableException` today leaves no trace anywhere except the caller's own exception handling (or lack of it) — a production failure is invisible until someone notices the business symptom.
3. **No correction primitive.** There is no way to undo or repair a wrongly-loaded plaza or ocupación — no "delete this link", no "reopen this plaza" — short of a direct SQL fix. This is acceptable ONLY because no UI exists yet to make the mistake in the first place; it becomes a blocker the moment slice 3 (or any admin screen) lets a human load real data.
4. **No check that the tables are actually InnoDB.** Every invariant this slice defends inside a transaction (at most one vigent ocupación, atomic close-then-insert) silently depends on `ENGINE=InnoDB` actually taking effect. If a hosting provider's `dbDelta()` run substitutes a non-transactional engine (some managed MySQL configurations do this transparently), `START TRANSACTION` / `ROLLBACK` become no-ops and every invariant in this README degrades without any error ever being raised.

## The dictamen engine (slice 3)

Slice 3 is the pure-function decision layer on top of slice 2's data model: given a `Dictamen\SolicitudDeCambio` and a fully-assembled `Dictamen\DictamenContext` (zero DB access, zero clock — see that class's docblock), `Dictamen\DictamenEngine` runs every `Dictamen\Regla` and joins every `Dictamen\Motivo` they report into one `Dictamen\Dictamen`. It ships no REST routes, no admin UI, no context assembly from the real database — see "Explicitly out of scope" below.

### A dictamen is not an approval

`Dictamen::procede()` is exactly "zero motivos" — never a stand-in for `aprobado()`. Per the reglamento, the subcomisión is never obligated to provide a reemplazo, and the process owner approves ALWAYS, even when a solicitud clears every rule. See `DictamenEngine`'s class docblock for the full reasoning.

### Never short-circuits, never lets one rule's crash erase another's motivo

`DictamenEngine::evaluate()` runs all seven rules unconditionally and collects every motivo — a captain reading a rejected solicitud sees every reason at once. A `Regla` that throws (corrupted business data it cannot evaluate at all, e.g. a `puntaje_techo` outside `Plazas\Puntaje`'s valid range) is caught per-rule and turned into its own fail-closed motivo (`error_al_evaluar_regla`, tagged with the offending rule's class name) — it does not abort the loop and does not discard the motivos other rules already found.

### The ruleset has exactly one source of truth: `DictamenEngineFactory`

`Dictamen\DictamenEngineFactory::create()` (and `::reglas()`) is the ONLY place, production or test, that lists the seven rules. `DictamenEngineFactoryTest` locks that list down; every other test builds its engine through the factory instead of keeping a parallel copy — the same shape of bug as `Calendario\EstadoDeriver` forgetting a state, closed by having exactly one enumeration to forget.

### The seven rules

`Reglas\PuntajeDentroDelTecho`, `Reglas\EntranteNoBloqueado`, `Reglas\EntranteDisponible`, `Reglas\EntranteNoEsElSaliente`, `Reglas\SolicitudEnPlazo`, `Reglas\PlazaConOcupacionVigente`, `Reglas\RegresoSoloConMinimoCumplido` — each a pure function of `DictamenContext`, independent of the others (see `Regla`'s class docblock). Two are worth calling out:

- **`PuntajeDentroDelTecho`** treats a missing `entrantePuntaje` differently by `tipo`: null is the type's own guarantee for a `regreso` (no objection), but for a `sustitucion` it is an unresolved external lookup and is reported as its own motivo (`entrante_puntaje_indeterminado`) rather than silently read as "no objection" — see that class's docblock.
- **`EntranteNoBloqueado`** takes CC5b's still-unconfirmed policy (`BloqueoReemplazoPolicy::topeTresFechas()` vs `::hastaLiberacionDePlaza()`) as a constructor parameter, defaulting to the less severe reading. The motivo it produces carries `datos()['politica']` naming which reading fired, so the process owner's eventual answer can be checked against what was actually applied to past solicitudes.

### Explicitly out of scope

- **Assembling `DictamenContext` from the real database** (`PlazaRepository`, `FechaRepository`, `Calendario\Settings` + `PlazosCalculator`) is slice 4's job — every test in this slice hands the context a hand-built fixture.
- **REST routes, admin UI, wiring a caller.** This slice is domain logic only.

### Contracts for slice 4 — written here, not yet implemented

1. **Every query that fills `DictamenContext` must throw on failure, never return `[]`.** An empty `entranteOcupacionesEnOtrasPlazas()` or `entrantePlazasConCierreTruncado()` reads as "confirmado, sin conflicto" to `Reglas\EntranteDisponible` and `Reglas\EntranteNoBloqueado` — a query that fails silently becomes a silent approval, not a rejection.
2. **The wrapper that invokes `DictamenEngine` must catch `\Throwable`, log it with the solicitud's identifiers (seasonId, teamId, plazaId, fechaId), and only then decide what to answer the caller.** Without that, "the subcomisión rejected it" and "the dictamen engine crashed" are indistinguishable to whoever reads the response. `Observability\EventLog` (see "Guardrails" below) is the channel that wrapper should log through once it exists — nothing invokes `DictamenEngine` from a real caller yet.
3. **When CC5b is resolved, remove `BloqueoReemplazoPolicy`'s default** (`EntranteNoBloqueado`'s constructor currently defaults to `topeTresFechas()` when no policy is injected) — whoever wires `DictamenEngineFactory::create()` for real must pass the confirmed policy explicitly, so a future ambiguity cannot silently fall back to a guess again.

## Guardrails (slice 4)

Before slice 4, this plugin had no `error_log()` call and no hook anywhere — a captain reporting "I asked for a change and nothing happened" had nothing to look at. This slice adds the observability and defensive checks a real endpoint needs before it can go live; it still ships no REST routes, no admin UI, no cron (see "Scope of this slice" below) — it stays domain infrastructure.

### `Observability\EventLog` — the one channel every write and every failure speaks through

`EventLog` is a plain interface (`record(string $evento, array $contexto): void`). `WpEventLog` (production) always fires `do_action('entre_redes_cambios_event', $evento, $contexto)` and additionally writes to `error_log()` — and ONLY `error_log()`, no custom file — when `WP_DEBUG` is active. `InMemoryEventLog` (tests) keeps every event in order, so a test can assert not just that an event fired but that it fired BEFORE an exception propagated.

`Plazas\PlazaRepository` and `Capitania\CapitanRepository` both take an `EventLog` as a MANDATORY constructor argument — there is deliberately no null-object default. A default that silently swallowed events would reproduce, in code, the exact silent-failure pattern this slice exists to end. Every successful write that matters to an operator (`plaza.abierta`, `ocupacion.sucedida`, `ocupacion.regreso_titular`, `ocupacion.deshecha`, `plaza.cerrada`, `capitan.designado`, `capitan.revocado`) is recorded AFTER its `COMMIT`; every failure (`escritura.fallida`) is recorded BEFORE the exception is thrown, with the ids needed to diagnose it (season, team, plaza, ocupación, player, fecha, and `$wpdb->last_error` when applicable).

### Fecha id validation — `PlazaRepository`

`fecha_desde_id` / `fecha_hasta_id` are LOGICAL foreign keys into `cambios_fecha` with no DB-level constraint (same "SQLite shim drops every KEY" story as everywhere else in this schema — see `InitialSchema`'s docblock). `openPlaza()`, `succeedOcupacion()` and `closeOcupacionByRegresoTitular()` now validate — via `assertFechaExistsInSeason()` — that the id exists AND belongs to the same season as the plaza, before writing anything. An id typo or one copied from the wrong season now fails loud, at the write, instead of surfacing much later inside `Calendario\FechaRepository::countResolvedFechasSince()`.

### Correction primitives — `PlazaRepository::undoLastOcupacion()` / `::closePlaza()`

Before this slice, fixing a plaza opened with the wrong titular, or an ocupación succeeded by mistake, meant raw SQL against production — run by a parents' committee, not a developer. `undoLastOcupacion()` deletes the last link of a plaza's chain and reopens the one before it (refusing on a single-link/genesis-only chain — use `closePlaza()` for that case instead). `closePlaza()` marks a plaza's `closed_at` for one opened entirely by mistake. Both are explicitly CORRECTION tools, not part of the normal solicitud/regreso flow — nothing in `Dictamen\DictamenEngine` or `Plazas\CadenaResolver` ever calls them.

### InnoDB storage-engine check — `MigrationRunner`

Every invariant this plugin defends ("at most one vigent ocupación/captain") depends on `START TRANSACTION` / `COMMIT` / `ROLLBACK` being real, which requires every `cambios_` table to actually be InnoDB. MySQL accepts `ENGINE=InnoDB` and silently substitutes another engine on some hosts, WARNING but not failing. `MigrationRunner::run()` now checks each table's real engine via `information_schema.TABLES` after migrations, logs `motor.no_innodb` and shows an `admin_notice` if any table isn't InnoDB — tolerantly: if the query itself is unavailable (e.g. the SQLite test shim has no `information_schema`), it is treated as "could not check", never as "found a violation".

## Scope of this slice (slice 0)

This is a "pure function, zero UI" slice: `Plugin::boot()` intentionally registers no REST routes, no admin screens, and no cron jobs. It only runs migrations on activation. The calendar admin screen, the solicitud/regreso REST endpoints, and the seeding cron are later slices, built on top of the domain logic here once it is validated.

Slice 1 (captaincy and authorization, above) keeps the same discipline: no REST routes, no admin UI, no cron. It is domain logic only, ready for the slices that will actually expose it.

Slice 2 (plazas and ocupaciones, above) keeps it too: no REST routes, no admin UI, no cron, no dictamen engine, no backfill. It is domain logic only — the data model and the derivations a later slice's dictamen engine and endpoints will call.
