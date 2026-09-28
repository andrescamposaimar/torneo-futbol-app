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

# 2. Activate plugin in WP admin — creates the 3 cambios_ tables and seeds
#    cambios_settings with the default timezone, season_id and plazo offsets.

# 3. Run the test suite
composer test
```

## Table structure

The plugin creates 4 custom tables prefixed with `{wp_prefix}cambios_`:

| Table | Purpose |
|-------|---------|
| `cambios_fecha` | One row per jornada of the ENTIRE season (Apertura + Clausura together) — never per-zone |
| `cambios_fecha_partido` | Bridge to the `sp_event` matches that belong to a fecha |
| `cambios_settings` | Operator-configurable parameters: timezone, season_id, and the four plazo offsets |
| `cambios_capitan` | One row per captaincy DESIGNATION (history + current state) — see "Captaincy and authorization" below |

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

## Scope of this slice (slice 0)

This is a "pure function, zero UI" slice: `Plugin::boot()` intentionally registers no REST routes, no admin screens, and no cron jobs. It only runs migrations on activation. The calendar admin screen, the solicitud/regreso REST endpoints, and the seeding cron are later slices, built on top of the domain logic here once it is validated.

Slice 1 (captaincy and authorization, above) keeps the same discipline: no REST routes, no admin UI, no cron. It is domain logic only, ready for the slices that will actually expose it.
