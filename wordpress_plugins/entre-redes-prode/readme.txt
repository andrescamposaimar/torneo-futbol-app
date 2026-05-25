=== Entre Redes — Prode Interno ===
Contributors: entreredes
Tags: football, predictions, prode, tournament
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 0.1.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticated predictions game for the Entre Redes football league.

== Description ==

The Prode Interno plugin adds a predictions (prode) game layer to the Entre Redes league app. Players register via Google or Apple SSO, confirm their identity with their DNI, and submit predictions for upcoming matches. After matches are played, scores are computed automatically and rankings are displayed in the mobile app.

**Requires**: the Entre Redes base plugin must be installed and active.

**Configuration**: define `PRODE_TENANT_ID` in your `wp-config.php` before activating.

== Installation ==

1. Install and activate the Entre Redes base plugin.
2. Add `define( 'PRODE_TENANT_ID', 'your-tenant-slug' );` to `wp-config.php`.
3. Upload `entre-redes-prode/` to the `wp-content/plugins/` directory.
4. Run `composer install --no-dev` inside the plugin directory.
5. Activate the plugin from the WordPress admin Plugins screen.
6. Verify: `GET /wp-json/entre-redes/v1/prode/healthcheck` returns `{"status":"ok"}`.

See `docs/entre-redes-prode-runbook.md` for full provisioning instructions.

== Changelog ==

= 0.1.0 =
* Initial scaffold: plugin structure, 10-table schema, healthcheck endpoint, JWKS endpoint.
