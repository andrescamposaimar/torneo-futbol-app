# Entre Redes — Prode Interno

WordPress plugin that adds an authenticated predictions game to the Entre Redes football league.

## Requirements

- PHP 8.0+
- WordPress 6.2+
- MySQL with InnoDB engine
- Entre Redes base plugin (active)
- `PRODE_TENANT_ID` constant in `wp-config.php`
- Composer (for `firebase/php-jwt` and `ramsey/uuid`)

## Quick start

```bash
# 1. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 2. Add to wp-config.php
define( 'PRODE_TENANT_ID', 'marianista' );

# 3. Activate plugin in WP admin

# 4. Verify activation
curl https://your-site.com/wp-json/entre-redes/v1/prode/healthcheck
# → {"status":"ok","plugin":"entre-redes-prode","version":"0.1.0","tenant_id":"marianista"}
```

## Table structure

The plugin creates 10 custom tables prefixed with `{wp_prefix}prode_`:

| Table | Purpose |
|-------|---------|
| `prode_users` | Standalone Prode user records (no wp_users coupling) |
| `prode_associations` | SSO provider ↔ DNI ↔ player link |
| `prode_refresh_tokens` | Rotating refresh token store |
| `prode_fechas` | Prediction rounds |
| `prode_fecha_matches` | Matches within a fecha |
| `prode_predictions` | User predictions per match |
| `prode_scores` | Evaluated points per (user, match) |
| `prode_ranking_fecha_cache` | Materialized per-fecha ranking |
| `prode_audit_log` | Association lifecycle audit trail |
| `prode_settings` | Operator-configurable parameters |

## Endpoints (PR-01)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/wp-json/entre-redes/v1/prode/healthcheck` | Plugin liveness check |
| GET | `/wp-json/entre-redes/v1/prode/.well-known/jwks.json` | RS256 public key (JWK format) |
| POST | `/wp-json/entre-redes/v1/prode/recompute-rankings` | Force a full rebuild of every evaluated fecha's ranking cache (admin-only) |

Auth, game, and account endpoints are added in subsequent PRs.

### POST /prode/recompute-rankings

Admin-only (`manage_options`). Forces `prode_ranking_fecha_cache` to be rebuilt
for every evaluated fecha of the tenant — the same work `RankingCron` does on
its normal event-driven trigger, just synchronous and on demand. Reach for
this when the cache looks stale (e.g. after a manual DB fix, or while
diagnosing a discrepancy) and you don't want to wait for the next evaluation
to fire it as a side effect. Takes no parameters — the rebuild is always
global, never scoped to one fecha.

Response:

```json
{
  "status": "ok",
  "fechas_processed": 3,
  "skipped_unscored": 1,
  "skipped_empty": 0,
  "computed_at": "2026-09-13 12:00:00"
}
```

## Result-change self-heal

If an operator corrects a played match's score in SportsPress (e.g. 2-2 → 2-1)
on a fecha the plugin already evaluated, the plugin repairs itself instead of
leaving `prode_scores` / `prode_ranking_fecha_cache` stale:

- `ResultChangeListener::onSavePost()` is bound to WordPress's `save_post`
  hook at **priority 20** (never `save_post_sp_event` — see the class
  docblock for why priority alone can't fix that hook choice) and detects a
  changed result on an already-`evaluated` fecha.
- It purges the plugin's own `/partidos` transient cache and schedules the
  `prode_reevaluate_fecha` cron action ~30 seconds later, so several quick
  corrections to the same fecha collapse into a single repair pass (WP-Cron
  dedupes identical `(hook, args)` schedules within a 10-minute window).
- `ReevaluateFechaCron::run()` handles that action by calling
  `FechaEvaluator::evaluateFecha()` directly — the same idempotent evaluation
  used by the daily cron and the manual admin endpoint.

Like `prode_evaluate_matches_cron`, this relies on WP-Cron being triggered
(page loads, or a system cron hitting `wp-cron.php` — see the runbook §3):
on a low-traffic install the repair may take longer than 30 seconds to fire.

## Full documentation

See `docs/entre-redes-prode-runbook.md` for installation, configuration, and operations.
