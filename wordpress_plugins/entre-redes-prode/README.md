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

Auth, game, and account endpoints are added in subsequent PRs.

## Full documentation

See `docs/entre-redes-prode-runbook.md` for installation, configuration, and operations.
