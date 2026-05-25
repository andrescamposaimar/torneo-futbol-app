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

1. Download the new version ZIP.
2. Deactivate the current plugin.
3. Delete the plugin (WP will NOT run uninstall.php if you bypass it by uploading a new version; see step 4).
4. Upload and install the new ZIP.
5. **Do not activate yet** — run `composer install --no-dev --optimize-autoloader` in the plugin directory.
6. Activate. The MigrationRunner will detect the version mismatch and run dbDelta to apply any new columns or indexes. Existing data is preserved.

---

## 9. Key rotation

[TODO — PR-11: RS256 key rotation procedure and DNI pepper rotation via WP-CLI `wp prode rotate-pepper`.]

---

## 10. WP-CLI commands

[TODO — PR-11: `wp prode evaluate-fecha <id>`, `wp prode recompute-rankings`, `wp prode rotate-pepper [--dry-run|--apply]`.]

---

## 11. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| Plugin self-deactivates on activation | `PRODE_TENANT_ID` not defined OR Entre Redes base plugin not active | Define the constant in wp-config.php; activate Entre Redes first |
| Healthcheck returns 503 | RSA key pair not generated (OpenSSL not available) | Ensure OpenSSL PHP extension is enabled; reactivate the plugin |
| Tables not created | dbDelta error; often a charset/engine mismatch | Check MySQL default engine is InnoDB; see WP admin notices after activation |
| Predictions not evaluating | WP cron not firing | Set up system cron (§3) |

---

*Last updated: 2026-05-25 — PR-01 (plugin scaffold)*
