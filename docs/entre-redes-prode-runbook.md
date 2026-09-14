# Entre Redes — Prode Interno: Runbook

> Status: SKELETON — sections marked [TODO] are filled in PR-11.
> This document is operator-facing. All commands assume a cPanel-based shared hosting environment.

---

## 1. Installation

### 1.1 Prerequisites

- WordPress 6.0+ installed and running.
- Entre Redes base plugin installed and **active**.
- PHP 8.0+ with the OpenSSL extension enabled.
- MySQL with InnoDB as the default storage engine.
- SSH or cPanel File Manager access to `wp-content/plugins/`.

### 1.2 Upload the plugin

1. Download or build the plugin ZIP (see §6 Build script).
2. In WordPress admin → Plugins → Add New → Upload Plugin, select the ZIP.
3. Click **Install Now**.

> Alternatively, unzip into `wp-content/plugins/entre-redes-prode/` via SSH or cPanel.

### 1.3 Install PHP dependencies (required before activation)

```bash
cd /path/to/wp-content/plugins/entre-redes-prode
composer install --no-dev --optimize-autoloader
```

If `composer` is not available on the hosting server, build the ZIP locally with dependencies included (see §6).

### 1.4 Configure wp-config.php

Add the following constants **before** `/* That's all, stop editing! */`:

```php
// Entre Redes Prode — required
define( 'PRODE_TENANT_ID', 'marianista' );  // Change to your tenant slug.
```

The plugin will refuse to activate if `PRODE_TENANT_ID` is not defined.

### 1.5 Activate the plugin

In WordPress admin → Plugins, click **Activate** next to "Entre Redes — Prode Interno".

On activation, the plugin:
- Creates all 10 `wp_prode_*` tables (InnoDB, utf8mb4).
- Generates an RSA 2048-bit key pair (stored in WP options).
- Generates a random DNI audit pepper (stored in WP options).
- Seeds default settings (`lock_hours_before=24`, etc.).
- Schedules cron jobs.

### 1.6 Post-activation verification

```bash
curl https://your-site.com/wp-json/entre-redes/v1/prode/healthcheck
```

Expected response:

```json
{
  "status": "ok",
  "plugin": "entre-redes-prode",
  "version": "0.1.0",
  "tenant_id": "marianista"
}
```

Verify the JWKS endpoint returns the public key:

```bash
curl https://your-site.com/wp-json/entre-redes/v1/prode/.well-known/jwks.json
```

Expected: a JSON object with a `keys` array containing one RSA JWK (`kty: "RSA"`).

---

## 2. PRODE_TENANT_ID configuration

[TODO — PR-11: document how to choose a tenant slug, consequences of changing it after activation, and multi-tenant considerations.]

---

## 3. cPanel Cron Job setup

WordPress cron requires page loads to trigger. On low-traffic installs, evaluations may be delayed. Configure a system cron to fire WordPress cron reliably:

```
*/5 * * * * php /path/to/wordpress/wp-cron.php > /dev/null 2>&1
```

Or using WP-CLI (preferred):

```
*/5 * * * * /usr/local/bin/wp --path=/path/to/wordpress cron event run --due-now > /dev/null 2>&1
```

[TODO — PR-11: step-by-step screenshots for cPanel Cron Jobs interface.]

---

## 4. Google OAuth provisioning

[TODO — PR-11: step-by-step Google Cloud Console instructions for web + Android + iOS client IDs per flavor.]

---

## 5. Apple Sign-In provisioning

[TODO — PR-11: Apple Developer Portal steps — Services ID, SIWA capability, per-flavor configuration.]

---

## 6. Build script

[TODO — PR-11: document `scripts/build-prode-plugin.sh` — how to produce a deployable ZIP with vendored dependencies.]

---

## 7. Uninstall procedure

> WARNING: Uninstalling drops all prode_ tables permanently. Take a DB backup first.

1. Go to WordPress admin → Plugins.
2. Deactivate **Entre Redes — Prode Interno**.
3. Click **Delete**.
4. WordPress calls `uninstall.php` which drops all 10 `wp_prode_*` tables and deletes all plugin WP options.

To preserve data, deactivate the plugin without deleting it. All tables and data are retained on deactivation.

---

## 8. Upgrading the plugin

> **NEVER click Delete to upgrade.** Deleting the plugin from the WordPress admin
> runs `uninstall.php`, which **drops all 10 `wp_prode_*` tables** — every user,
> prediction, score and ranking, permanently. §7 describes that path correctly.
> A previous revision of this section instructed operators to delete the plugin
> and claimed WordPress would skip `uninstall.php`. That claim was wrong.
> Use the replace flow below, which never invokes the uninstall hook.

1. Build the ZIP: `./wordpress_plugins/build-prode.sh --with-dev`
   (the script vendors production dependencies into the artifact and refuses to
   package a tree that would ship without them — see §6. `--with-dev` restores
   phpunit afterwards so the test suite still runs locally.)
2. WordPress admin → **Plugins → Add New → Upload Plugin**, select the ZIP.
3. WordPress detects the installed copy and shows a side-by-side comparison of
   the current and uploaded versions. Click **Replace current with uploaded**.
   This deactivates, swaps the files and reactivates. It does **not** call
   `uninstall.php`, so all data is preserved.
4. No `composer install` step is needed: the ZIP already contains `vendor/`.
   (Only relevant if you deploy by unzipping by hand instead of using §6.)
5. On activation the MigrationRunner compares `prode_db_version` against
   `ENTRE_REDES_PRODE_VERSION` and runs `InitialSchema::up()` when they differ.
   That path is idempotent (`dbDelta` plus `INSERT IGNORE`), so it adds new
   columns and indexes without touching existing rows or settings. Crons are
   rescheduled on every activation, not only on a version bump.
6. Verify the deployed version before you walk away:
   `curl -s https://<site>/wp-json/entre-redes/v1/healthcheck`
   The reported `version` must match the ZIP you just uploaded. A stale version
   here means the replace did not take.

**Bumping the version matters.** `build-prode.sh` reads `Version:` from
`entre-redes-prode.php`, and MigrationRunner gates schema updates on it. Shipping
a changed plugin under an unchanged version number means the migration never runs.

---

## 9. Key rotation

[TODO — PR-11: RS256 key rotation procedure and DNI pepper rotation via WP-CLI `wp prode rotate-pepper`.]

---

## 10. WP-CLI commands

[TODO — PR-11: `wp prode evaluate-fecha <id>`, `wp prode rotate-pepper [--dry-run|--apply]`.]

The production host is cPanel shared hosting with **no WP-CLI available**, so
a `wp prode recompute-rankings` command would be useless there even if it
existed. §12a documents the REST route that replaces it.

---

## 11. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| Plugin self-deactivates on activation | `PRODE_TENANT_ID` not defined OR Entre Redes base plugin not active | Define the constant in wp-config.php; activate Entre Redes first |
| Healthcheck returns 503 | RSA key pair not generated (OpenSSL not available) | Ensure OpenSSL PHP extension is enabled; reactivate the plugin |
| Tables not created | dbDelta error; often a charset/engine mismatch | Check MySQL default engine is InnoDB; see WP admin notices after activation |
| Predictions not evaluating | WP cron not firing | Set up system cron (§3) |

---

## 12. Correcting a result after evaluation (self-heals automatically)

As of v0.9.4, correcting a played match's score in SportsPress (e.g. 2-2 → 2-1)
on a fecha that was **already evaluated** no longer requires any manual step.

Saving the corrected `sp_event` post triggers `ResultChangeListener`, which
purges the plugin's `/partidos` cache and schedules a repair evaluation
(`prode_reevaluate_fecha`) roughly 30 seconds later. That repair re-reads the
live result, re-scores every prediction for the fecha, and recomputes the
ranking cache — exactly like the original evaluation.

This depends on WP-Cron being triggered, same as the daily evaluation pass
(§3) — on a low-traffic install without a system cron hitting `wp-cron.php`,
the repair can be delayed until the next page load.

**Fallback (manual reopen-and-evaluate)** — use this only if the automatic
repair does not appear to have run (check `prode_scores.evaluated_at` /
`prode_ranking_fecha_cache` for the fecha):

1. In the database, set the affected `prode_fechas` row's `state` back to an
   evaluable state (not `'evaluated'`) so `POST /prode/evaluar-fecha` accepts
   it, or trigger evaluation directly via WP-CLI if available.
2. Re-run the evaluation for that fecha (admin "Evaluate" action, or the
   equivalent WP-CLI command — see §10).
3. Verify `prode_scores` and `prode_ranking_fecha_cache` reflect the corrected
   result for every affected match.

---

## 12a. Forcing a ranking rebuild (POST /prode/recompute-rankings)

As of v0.9.5, `prode_ranking_fecha_cache` can be rebuilt on demand through a
REST endpoint. This **replaces the previous workaround** of dropping a
temporary mu-plugin that called
`do_action('prode_recompute_rankings_cron')` by hand — that workaround is no
longer needed and should not be used going forward.

Use this when the cache looks stale and you don't want to wait for the next
evaluation (or the result-change self-heal in §12) to recompute it as a side
effect — for example after a manual database fix, or while diagnosing a
ranking discrepancy.

The endpoint is admin-only (`manage_options`) and takes no parameters: it
always rebuilds every evaluated fecha for the tenant, exactly like the
`RankingCron` scheduled job does. There is no per-fecha scoping.

**Since the production server has no WP-CLI (§10), the supported way to call
this endpoint is from the browser console**, on any wp-admin **block editor**
screen while logged in as an administrator (the block editor page already
loads `wp.apiFetch` with the current session's nonce):

```js
wp.apiFetch({ path: '/entre-redes/v1/prode/recompute-rankings', method: 'POST' }).then(console.log)
```

Expected output:

```json
{
  "status": "ok",
  "fechas_processed": 3,
  "skipped_unscored": 1,
  "skipped_empty": 0,
  "computed_at": "2026-09-13 12:00:00"
}
```

This call is **synchronous and can take a few seconds** on a tenant with many
evaluated fechas: `RankingCron` recomputes every one of them from a full
`SUM(points)` aggregation each time, not incrementally. That's expected and
acceptable — this route is only ever triggered by an operator on demand, not
on a path a player waits on.

---

*Last updated: 2026-09-13 — documented POST /prode/recompute-rankings (§12a), which replaces the temporary mu-plugin workaround for forcing a ranking rebuild.*
