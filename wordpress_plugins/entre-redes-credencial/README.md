# Entre Redes — Credencial Virtual

WordPress plugin that adds a virtual player credential (photo, rotating liveness code, offline cache) to the Entre Redes football league.

## Requirements

- PHP 8.0+
- WordPress 6.2+
- Entre Redes base plugin (active)
- `entre-redes-prode` plugin (active) — this plugin verifies the RS256 JWTs prode issues; see `src/Auth/TokenVerifier.php`
- Composer (for `firebase/php-jwt`)

## Quick start

```bash
# 1. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 2. Activate plugin in WP admin
```

## Building the deployable zip

Run `../build-plugin.sh entre-redes-credencial` from `wordpress_plugins/`. It
runs `composer install --no-dev`, verifies `vendor/firebase/php-jwt` is
present and actually verifies a signed token (not just a class-exists check),
and refuses to produce a zip that would ship without it — the same gate that
exists because an early entre-redes-prode release once shipped
`vendor/`-less and still reported a healthy healthcheck.

## Running tests

```bash
composer install   # restores phpunit (pruned by --no-dev above)
composer test
```
