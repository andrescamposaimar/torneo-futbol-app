# Manual QA Checklist — PR-09: Admin Panel (prode-wp-admin-panel)

**Purpose**: Manual verification of all 51 acceptance criteria from the spec.
**When to run**: After deploying PR-C to a staging environment with a populated `prode_*` dataset.
**Prerequisite**: Logged in to wp-admin as a user with `manage_options` capability (administrator role).

---

## Environment Setup

- [ ] Plugin active and database migrated (`wp prode migrate` or on activation).
- [ ] At least 1 row in `wp_prode_settings` for each of the five setting keys.
- [ ] At least 1 row in `wp_prode_users` with an active `wp_prode_associations` row.
- [ ] At least 1 row in `wp_prode_audit_log`.
- [ ] Cron hooks scheduled (`wp cron event list`).

---

## Cross-Cutting Requirements

| AC | Description | Steps | Pass | Fail |
|----|-------------|-------|------|------|
| CC-01 | manage_options gate on render AND POST | (a) Log out and visit `/wp-admin/admin.php?page=prode-settings` directly — should redirect to login. (b) Log in as subscriber, visit the page — should see wp_die error. | | |
| CC-02 | Nonce on every POST | Submit the settings form with browser devtools — confirm `prode_settings_nonce` field present. Forge a POST without it — confirm wp_die fires. | | |
| CC-03 | esc_html/esc_attr on all output | Inject `<script>alert(1)</script>` as a setting value, save, reload — confirm HTML is escaped, not executed. | | |
| CC-04 | Spanish UI strings | Visit all three pages — confirm all labels, notices, and button text are in Spanish. | | |
| CC-05 | Zero crypto option values in HTML | Inspect page source of all three pages — confirm `prode_rsa_private_key`, `prode_rsa_public_key`, `prode_rsa_key_id`, `prode_audit_dni_pepper` do not appear anywhere. | | |
| CC-06 | No wp_users JOIN | Run `SHOW PROCESSLIST` or query log while loading each page — confirm no JOIN to `wp_users` in any query. | | |
| CC-07 | Settings.php getters exist | Run `wp eval 'global $wpdb; $s = new \EntreRedes\Prode\Fecha\Settings($wpdb); echo $s->lockWarningHoursBefore() . " " . $s->evaluatorCronIntervalMinutes();'` — should print `2 5` (defaults). | | |

---

## Page 1: Configuración (`prode-settings`)

| AC | Description | Steps | Pass | Fail |
|----|-------------|-------|------|------|
| CONF-01 | 5 editable prode_settings fields | Visit the page — confirm inputs for: `lock_hours_before`, `lock_warning_hours_before`, `fecha_window_days`, `prode_season_id`, `evaluator_cron_interval_minutes`. | | |
| CONF-02 | 2 editable WP option fields | Confirm inputs for `Google Client ID` and `Audience de Apple`. | | |
| CONF-03 | Constant-override read-only | Define `PRODE_GOOGLE_CLIENT_ID` as a PHP constant in `wp-config.php`. Reload the page — confirm the Google Client ID field is disabled and shows a "set via constant" notice. Attempt to POST with a forged value — confirm `update_option` is NOT called (check DB). | | |
| CONF-04 | PRODE_TENANT_ID and prode_db_version read-only | Confirm both values appear in the "Información del sistema" section as text (not form inputs). | | |
| CONF-05 | 4 cron next-run timestamps | Confirm the "Estado de tareas programadas" section shows the next scheduled datetime for all four cron hooks, or "No programado" for unscheduled ones. | | |
| CONF-06 | Validation rules | (a) Submit with `lock_warning_hours_before` = `lock_hours_before` (e.g. both = 5) — confirm Spanish error notice appears, no DB write. (b) Submit with empty `fecha_window_days` — confirm error. (c) Submit with `prode_season_id = 0` — confirm error. | | |
| CONF-07 | Save flow — updated_at/updated_by, success notice | Save valid values — confirm success notice appears in Spanish, DB rows show updated `updated_at` and `updated_by` = current WP user ID. | | |
| CONF-08 | Seed-fecha button: nonce, 3 result notices, redirect | (a) Click "Crear fecha próxima" with no upcoming matches — confirm "No se encontraron partidos" notice. (b) Click again when a fecha already exists — confirm "Ya existe una fecha activa" notice. (c) In a clean state, click when matches exist — confirm "Fecha creada correctamente" notice with fecha_id and match count. | | |
| CONF-09 | Seed-fecha idempotency | Click the button twice for the same play-date — confirm second click shows `reused` notice, not a duplicate fecha in the DB. | | |
| CONF-10 | Non-numeric POST rejected | Submit with `lock_hours_before = abc` (use devtools to change the input type) — confirm error notice, no DB write, no silent `intval('')`. | | |
| CONF-11 | Bad nonce → wp_die | Forge a POST to `prode-settings` with a tampered or absent nonce — confirm `wp_die` fires with Spanish error message. | | |
| EDGE-01 | Updated_by/at metadata displayed | Verify `updated_at` and `updated_by` metadata appear below each settings field (or in a summary section). | | |
| EDGE-02 | Constant-controlled option not saved on POST | With `PRODE_GOOGLE_CLIENT_ID` defined, forge a POST including `prode_google_client_id=evil` — confirm DB value unchanged. | | |
| EDGE-04 | Seed-fecha with no upcoming matches | Trigger button with an empty season or no programmed matches — confirm "No se encontraron partidos" notice, no fecha created. | | |
| EDGE-06 | prode_settings row missing — INSERT fallback | Delete a `prode_settings` row manually. Submit the settings form — confirm row is INSERT-ed (not silently skipped), check DB. | | |

---

## Page 2: Registro de jugadores (`prode-registry`)

| AC | Description | Steps | Pass | Fail |
|----|-------------|-------|------|------|
| REG-01 | Data from prode_users + prode_associations, no wp_users | Check query log — confirm JOIN is `prode_users LEFT JOIN prode_associations`, no `wp_users` in any query. | | |
| REG-02 | 10 columns as specified | Confirm table has columns: ID, Nombre, Email, Proveedor, DNI, Jugador, Creado, Último login, Estado, Acciones. | | |
| REG-03 | 25/page pagination | With >25 active users, confirm pagination controls appear and page 2 shows the next 25 rows. | | |
| REG-04 | Activos/Eliminados filter tabs with counts | Confirm tabs appear above the table. Click "Eliminados" — table shows only soft-deleted users. Counts in tab labels match DB counts. | | |
| REG-05 | Desvincular only for rows with active association | Confirm users without an active association show "—" in Acciones column. | | |
| REG-06 | Unlink POST: per-row nonce, soft-delete, logAdminUnlink, redirect | Click "Desvincular" for a user, confirm dialog, confirm. Verify: (a) `prode_associations.deleted_at` is set, (b) `deleted_by = 'admin'`, (c) `deleted_actor_wp_id` = current WP user ID, (d) new row in `prode_audit_log` with `event_type = 'admin_unlink'`. | | |
| REG-07 | Post-unlink row has no action, status unchanged | After unlink, verify the user still appears in Activos tab with "—" in Acciones column. `prode_users.deleted_at` is NULL. | | |
| REG-08 | JS confirm includes player name | Click "Desvincular" — confirm the JS confirm dialog message includes the player identifier. | | |
| REG-09 | Empty state — Spanish no-items message | Filter so no users match (switch to Eliminados when none exist) — confirm Spanish "no se encontraron jugadores" message. | | |
| REG-10 | DNI displayed with esc_html | Confirm DNI column renders plain text (escaped). Inject `<b>test</b>` as a DNI value in DB — confirm HTML is not rendered. | | |
| EDGE-03 | Concurrent unlink — already_unlinked notice | Manually set `prode_associations.deleted_at` to a past timestamp. Then trigger the unlink POST for that user — confirm "El usuario ya fue desvinculado" informational notice appears (no error). | | |
| EDGE-07 | Audit write failure — unlinked_no_audit warning notice | Temporarily remove or corrupt the `prode_audit_dni_pepper` option (or undefine it) so `DniHasher::hash()` throws. Trigger an unlink POST for an active user. Confirm: (a) the unlink is applied (`prode_associations.deleted_at` is set), (b) NO row is inserted in `prode_audit_log`, (c) the operator sees a **yellow warning notice** (notice-warning) in Spanish stating the unlink succeeded but the audit entry could not be written, (d) restoring the pepper and unlinking a different user produces the normal green success notice. | | |

---

## Page 3: Bitácora (`prode-audit-log`)

| AC | Description | Steps | Pass | Fail |
|----|-------------|-------|------|------|
| BIT-01 | Query on prode_audit_log only | Check query log — confirm no JOINs to other tables. | | |
| BIT-02 | 7 columns as specified | Confirm table has: ID, Tipo de evento, Jugador, Proveedor, Actor WP, Fecha/Hora, Metadatos. | | |
| BIT-03 | No full hash displayed | Inspect page source — confirm `dni_hash`, `provider_id_hash`, `ip_address_hash` columns do not appear in the table. | | |
| BIT-04 | Read-only: no write actions | Confirm no form buttons, no action links, and no POST handler is reachable from this page. | | |
| BIT-05 | event_type dropdown filter | Select an event type from the dropdown and click Filtrar — confirm only rows of that type appear. Confirm the dropdown retains the selected value across pagination. | | |
| BIT-06 | Date range filter | Set `date_from` only — confirm older entries are excluded. Set `date_to` only — confirm newer entries are excluded. Set both — confirm bounded range. Set an invalid string (e.g. `not-a-date`) — confirm filter is silently ignored, all rows visible. | | |
| BIT-07 | 25/page pagination, filters preserved | With >25 log entries, click to page 2 — confirm URL retains `event_type`, `date_from`, `date_to` params. | | |
| BIT-08 | Empty state — Spanish no-items message | Apply a filter that matches zero rows — confirm Spanish "no se encontraron registros" message. | | |
| BIT-09 | metadata_json pretty-printed; null → "—"; esc_html applied | (a) Row with valid JSON — confirm indented output. (b) Row with null metadata — confirm "—". (c) Row with >300 char JSON — confirm truncation with "…". (d) Row with `<script>` in metadata JSON — confirm escaped in output. | | |
| EDGE-05 | Zero results with filters — filter inputs retain values | After filtering to zero results, confirm the filter form still shows the submitted values. | | |

---

## Settings.php Getter Tests (already covered by unit tests; verify in staging)

| AC | Description | Steps | Pass | Fail |
|----|-------------|-------|------|------|
| SET-01 | lockWarningHoursBefore() getter | Run `wp eval` command (see CC-07) — confirms default 2. Set `lock_warning_hours_before = 3` in DB — confirm getter returns 3. | | |
| SET-02 | evaluatorCronIntervalMinutes() getter | Default is 5 (see CC-07). Set `evaluator_cron_interval_minutes = 10` in DB — confirm getter returns 10. | | |

---

## Security Re-Checks

| Check | Steps | Pass | Fail |
|-------|-------|------|------|
| No crypto options in HTML | Grep page source of all three rendered pages for: `prode_rsa_private_key`, `prode_rsa_public_key`, `prode_rsa_key_id`, `prode_audit_dni_pepper`. Expect zero matches. | | |
| All forms have nonce fields | Inspect source of Configuración page — both the settings form and the seed-fecha form must each have a hidden nonce input. | | |
| Subscriber cannot access | Log in as subscriber role — visiting any prode-* admin URL must show wp_die or access denied. | | |

---

## Regression

| Check | Steps | Pass | Fail |
|-------|-------|------|------|
| Unit tests still green | Run `composer test` from `wordpress_plugins/entre-redes-prode/` — expect 290+ tests, all passing. | | |
| REST API unaffected | Run a quick REST smoke test: `curl -s .../wp-json/entre-redes/v1/partidos` — confirm response structure unchanged. | | |
| WP-CLI seed-fecha command | Run `wp prode seed-fecha --dry-run` (if supported) or `wp prode seed-fecha` — confirm no PHP errors, output unchanged from pre-PR-09. | | |

---

*Generated for PR-09 (prode-wp-admin-panel). Last updated: 2026-06-04.*
