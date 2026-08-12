#!/usr/bin/env bash
#
# Builds the deployable entre-redes-prode plugin zip.
#
# Why this exists: the 0.7.0 package was once assembled by hand without running
# `composer install`, so it shipped without `vendor/`. WordPress activated it,
# the REST routes registered, and the healthcheck reported "ok" — while every
# login died on a missing Firebase\JWT\JWT class. Packaging is now scripted, and
# the script refuses to produce a zip that would repeat that.
#
# Usage:  ./build-prode.sh            # build
#         ./build-prode.sh --with-dev # restore dev deps afterwards (for phpunit)
#
set -Eeuo pipefail

PLUGIN="entre-redes-prode"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$HERE/$PLUGIN"

[[ -d "$SRC" ]] || { echo "error: $SRC not found" >&2; exit 1; }
command -v composer >/dev/null || { echo "error: composer not on PATH" >&2; exit 1; }

VERSION="$(sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "$SRC/$PLUGIN.php" | head -1)"
[[ -n "$VERSION" ]] || { echo "error: could not read Version from $PLUGIN.php" >&2; exit 1; }

ZIP="$HERE/$PLUGIN-$VERSION.zip"
echo "==> building $PLUGIN $VERSION"

# Clear the target up front, not just before writing. A build that aborts at a
# gate must not leave last week's zip sitting there under the current version's
# name, looking freshly built to whoever uploads it next.
rm -f "$ZIP"

# Production dependency tree only — dev packages (phpunit et al) must never ship.
echo "==> composer install --no-dev"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$SRC" --quiet

# Gate: the exact failure this script exists to prevent. Checked before zipping
# so a broken tree cannot become an uploadable artifact.
for required in \
    "vendor/autoload.php" \
    "vendor/firebase/php-jwt/src/JWT.php" \
    "vendor/ramsey/uuid/src/Uuid.php"
do
    [[ -f "$SRC/$required" ]] || {
        echo "error: missing $required — refusing to package" >&2
        exit 1
    }
done

# Gate: prove the dependency actually loads and signs, not merely that files
# exist. Mirrors JwtService::selfTest() without needing a WordPress bootstrap.
echo "==> verifying the signing chain"
php -r '
require $argv[1] . "/vendor/autoload.php";
if ( ! class_exists( "Firebase\\JWT\\JWT" ) ) { fwrite( STDERR, "JWT class missing\n" ); exit( 1 ); }
if ( ! class_exists( "Ramsey\\Uuid\\Uuid" ) ) { fwrite( STDERR, "Uuid class missing\n" ); exit( 1 ); }
$k = openssl_pkey_new( [ "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA ] );
openssl_pkey_export( $k, $pem );
$t = Firebase\JWT\JWT::encode( [ "sub" => "1", "exp" => time() + 60 ], $pem, "RS256", "build-check" );
if ( ! is_string( $t ) || "" === $t ) { fwrite( STDERR, "signing produced no token\n" ); exit( 1 ); }
' "$SRC"

( cd "$HERE" && zip -r -q "$ZIP" "$PLUGIN" \
    -x "$PLUGIN/tests/*" \
       "$PLUGIN/.git/*" \
       "$PLUGIN/.gitignore" \
       "$PLUGIN/.phpunit.result.cache" \
       "$PLUGIN/phpunit.xml" \
       "*/.DS_Store" )

# Gate: verify the artifact itself, not the working tree it came from.
# The listing is captured once rather than piped into grep: under `pipefail`,
# `grep -q` exits at the first match, unzip takes SIGPIPE, and the pipeline
# reports failure even though the match was found.
LISTING="$(unzip -l "$ZIP")"

grep -q "$PLUGIN/vendor/firebase/php-jwt/src/JWT.php" <<<"$LISTING" || {
    echo "error: zip is missing firebase/php-jwt — refusing to publish" >&2
    rm -f "$ZIP"
    exit 1
}
if grep -q "$PLUGIN/tests/" <<<"$LISTING"; then
    echo "error: zip contains tests/ — refusing to publish" >&2
    rm -f "$ZIP"
    exit 1
fi

echo "==> ok: $ZIP"
echo "    $(tail -1 <<<"$LISTING" | awk '{print $2}') files, $(du -h "$ZIP" | cut -f1)"
echo "    sha256: $(shasum -a 256 "$ZIP" | cut -d' ' -f1)"

# `composer install --no-dev` prunes phpunit, so the test suite cannot run until
# the dev tree is restored. Opt in rather than doing it silently: leaving the
# production tree in place is what keeps a subsequent hand-made zip honest.
if [[ "${1:-}" == "--with-dev" ]]; then
    echo "==> restoring dev dependencies"
    composer install --optimize-autoloader --no-interaction --working-dir="$SRC" --quiet
    echo "    dev tree restored — 'composer test' is available again"
else
    echo "    note: dev deps pruned; run with --with-dev to restore phpunit"
fi
