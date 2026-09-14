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

## 2. "Vinculado a" / "ID" columns — the linked player must be visible, not just the state

Found in real use in production: the squad list's "Estado" column said a
row was linked "Automático" but never said *to whom*. Two columns were
added — `WpPlayerDirectory::findByIds()` resolves every linked row's real
name in one batched lookup per page render, never one query per row.

What `composer test` already proves (`SquadListTableTest`,
`WpPlayerDirectoryTest`, `TitleEditorPageTest`): the dash-for-unlinked
case, the dangling-pointer message, the directory-unavailable message,
the id column always showing the raw id, and the batched lookup being
reused instead of re-queried. What it cannot prove is what these actually
look like rendered in wp-admin — check by hand:

1. Open a title with at least one `auto`-linked row (e.g. from Task
   3.4's own end-to-end year). Confirm **"Vinculado a"** shows the
   registered player's real name (not the sheet abbreviation the squad
   row itself was entered under), and **"ID"** shows that player's plain
   numeric `sp_player` id.
2. Confirm a `sin_candidato` row, and a `manual` row with no pointer,
   both show a plain dash (`—`) in both columns — not a blank cell.
3. **Dangling pointer check**: pick a linked row's id from step 1, then
   on a **staging copy only**, unpublish (trash) that `sp_player` post.
   Reload the squad list. Confirm "Vinculado a" now reads something like
   *"ID {id} — jugador no encontrado"* (the id is still visible and
   still copyable), not a blank cell — a blank cell here would look like
   the link disappeared, when in fact it is still stored and simply
   points at a player the directory can no longer see. Restore
   (republish) the player afterward.
4. Confirm the "ID" column keeps showing the numeric id even for that
   dangling row from step 3 — the id column never depends on the name
   lookup succeeding.
5. In the row's "Vincular"/"Cambiar" action, paste the id you read from
   the "ID" column of a *different* row into the "ID jugador" field and
   submit. Confirm this is a workable way to relink a row by hand — that
   copy/paste reuse is the reason the id column exists at all.

A genuine directory-wide outage (every row showing "No se pudo verificar
(directorio no disponible)" plus a page-level warning notice, never a
per-row dangling-pointer message) is exercised by
`TitleEditorPageTest::test_render_shows_an_honest_notice_when_the_directory_is_unavailable`
and needs no manual check.

## 3. Task 3.5 — enter one real historical year end-to-end

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
with the invalid-nonce and handlePost()-driven tests to prove it.

Also covered by `composer test` and needing no manual check: the
`rowBelongsToRequestedTitle()` / `requireOwnedRow()` **authorization**
gate on every row-scoped `TitleEditorPage` action (`editar_fila`,
`eliminar_fila`, `vincular`, `cambiar`, `desvincular`) — a distinct
control from CSRF, per its own docblock, that rejects a row nonce which
is genuinely valid for its own row but paired with a different
`titulo_id` — and every notice key this slice introduced:
`error_fila_ajena`, `error_guardado`, `error_nombre_requerido`,
`fila_agregada_sin_vinculo` / `fila_agregada_sin_vinculo_directorio`,
`fila_actualizada_sin_vinculo` / `fila_actualizada_sin_vinculo_directorio`,
and the three-segment `revalidado_{succeeded}_{total}_{directoryErrors}`
notice on the Titles list page. The aggregate review queue (slice 4),
ranked player search (slice 5), and the bulk importer (slice 6) do not
exist yet.
