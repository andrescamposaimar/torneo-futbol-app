# Entre Redes — Copa Chaminade

WordPress plugin that records the historical championship record (Copa Chaminade) for the Entre Redes football league.

## Requirements

- PHP 8.2+
- WordPress 6.2+
- Composer (no production dependencies — only dev tooling, phpunit)

## Quick start

```bash
# 1. Install PHP dependencies (regenerates vendor/autoload.php for this
#    plugin's own PSR-4 map; there are no third-party runtime packages)
composer install --no-dev --optimize-autoloader

# 2. Activate plugin in WP admin
```

## Building the deployable zip

Run `../build-plugin.sh entre-redes-campeones` from `wordpress_plugins/`.
This plugin has no production dependencies, so the build skips the vendor
path and self-test gates (both are still mandatory for plugins that do have
one — see `build-plugin.sh`'s header for why those gates exist) but still
runs `composer install --no-dev`, excludes `tests/`, and inspects the
finished zip before trusting it.

## Running tests

```bash
composer install   # restores phpunit (pruned by --no-dev above)
composer test
```
