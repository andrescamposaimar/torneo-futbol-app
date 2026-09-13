# Staging verification — slice 3 (admin: title list + title editor + squad row CRUD + link/unlink)

Two things `composer test` cannot observe land in this slice. Both must be
checked by hand, on a staging copy of the site, before slice 6's bulk
import ever touches production data.

## 1. Task 2.12 (deferred from slice 2) — `WpPlayerDirectory`'s live queries

`WpPlayerDirectoryTest` (slice 2, round-9) scripts a fake `wpdb` to prove
the bucketing, the batched season query, and the `sp_current_team = '0'`
sentinel mapping to `null` all happen correctly **in this class's own
code**. It cannot prove those same queries behave correctly against real
`sp_player` posts, real `sp_season` terms, and real `sp_current_team`
postmeta — the SQLite shim has none of the three. Slice 3 is the first
place an operator can actually exercise `WpPlayerDirectory` end to end,
because `LinkResolver` (which consumes it) is now wired into the Titles
editor's add/edit-row flow and into "Revalidar".

Steps, on a staging copy with real data:

1. Activate the plugin (if not already) and open **Campeones → Agregar
   nuevo** to create a title for a year where you know several real,
   currently-registered players by name.
2. Add a squad row using a name that should surname-bucket to a **large**
   collision group (check `NameParserPropertyTest`'s informational output
   — `composer test -- --filter NameParserPropertyTest` prints the current
   collision-bucket list — and pick one of the larger buckets). Confirm the
   row resolves to `ambiguo` with a plausible candidate count, not `auto`
   with a suspiciously wrong single match and not `sin_candidato` when you
   know the player is registered.
3. Add a row using the exact registered title (`Apellido, Nombre` or the
   sheet abbreviation) of a player you know is registered for that year's
   season. Confirm it resolves `auto` and links to the right person — open
   their player profile from the front end in a second tab and compare.
4. Find (or create) a squad row that links to a real player whose
   `sp_current_team` postmeta holds the sentinel string `'0'` (a player with
   no current team) — or temporarily set one player's `sp_current_team` to
   `'0'` on a staging copy for this check only. Confirm nothing in the
   admin surfaces renders a broken team link, a team named "0", or a fatal
   error for that player. **This exact sentinel already caused a real,
   documented incident in this project (`runbook-prode-sin-equipo`)** —
   this check exists specifically because that failure mode is not
   hypothetical here.
5. Click "Revalidar" on the year you just built. Confirm every non-manual
   row is re-resolved without a PHP error or timeout, and that resolution
   still completes in a reasonable time for a normal-sized squad (roughly
   20-30 rows).

## 2. Task 3.5 — enter one real historical year end-to-end

Before slice 6's bulk importer exists, the editor built in this slice is
the *only* way any real historical title reaches the database. Walking one
real year through it by hand validates the whole chain — parser, resolver,
write, display — once, on real data, before any bulk load multiplies
whatever might be wrong by seventeen years.

Steps:

1. Pick one real historical year with a known team name and a squad list
   from an actual printed or digitized sheet (not a fabricated example).
2. Create the title via **Campeones → Agregar nuevo** (año, zona, posición,
   equipo).
3. Add every squad member from the sheet, one row at a time, in the order
   they appear on the sheet, marking the captain if the sheet marks one.
4. After entering the full squad, review the resulting link states:
   - Confirm every `auto` link is actually the right person (cross-check a
     handful against the front-end player profile).
   - Confirm every `ambiguo` row's candidate list actually contains the
     right person among the retained candidates.
   - Confirm `sin_candidato` rows are genuinely not registered under a
     name the matcher could reach — not a case where the registered
     `post_title` is simply not in `Apellido, Nombre` form (see design §3's
     "what this rule does NOT catch" — the correct fix there is editing the
     player's `post_title`, not widening the matcher).
5. Manually link (`Vincular`) at least one `ambiguo` or `sin_candidato` row
   using the row's known correct player id, and confirm it becomes `manual`
   and survives a "Revalidar" click unchanged.
6. Manually unlink (`Desvincular`) a currently-linked row and confirm it
   becomes `manual` with **no** pointer, and that "Revalidar" leaves it
   unlinked rather than silently re-linking it (LINK-9's "manual unlink
   survives re-validation" scenario).
7. Delete the title via **Eliminar** and confirm both the title row and
   every squad row are gone (`SELECT * FROM wp_campeones_plantel WHERE
   titulo_id = …` returns nothing).

## Out of scope for this checklist

Repository CRUD, the delete-transaction rollback, `LinkWriteService`'s
auto/ambiguo/manual writes, `RevalidationService`'s manual-exclusion,
CSRF/nonce verification on both admin pages (`TitlesPage` and
`TitleEditorPage`, all eight `TitleEditorPage` actions), and the
add-row/edit-row forms being reachable from `handlePost()` are all
covered by `composer test` and need no manual check — a prior version of
this section claimed this blanket coverage while the CSRF check did not
actually exist yet and the suite could not have proven it either way
(the test shim's `wp_verify_nonce()` returned truthy unconditionally, so
no test could ever make a nonce check fail). Both gaps are closed now,
with the invalid-nonce and handlePost()-driven tests to prove it. The
aggregate review queue (slice 4), ranked player search (slice 5), and the
bulk importer (slice 6) do not exist yet.
