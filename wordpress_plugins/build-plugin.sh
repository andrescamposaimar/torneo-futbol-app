#!/usr/bin/env bash
#
# Builds the deployable zip for any Entre Redes WordPress plugin in this
# directory.
#
# Why this exists: the entre-redes-prode 0.7.0 package was once assembled by
# hand without running `composer install`, so it shipped without `vendor/`.
# WordPress activated it, the REST routes registered, and the healthcheck
# reported "ok" — while every login died on a missing Firebase\JWT\JWT class.
# A broken plugin reporting itself healthy. Packaging is now scripted, and the
# script refuses to produce a zip that would repeat that.
#
# This used to be a prode-only script (build-prode.sh). It is now one script
# for all plugins because the same failure mode is not prode-specific: any
# plugin that composer-installs a production dependency can ship broken the
# same way if it is zipped by hand. credencial and cambios both depend on
# firebase/php-jwt and, until this script covered them, had no build script
# at all.
#
# The only thing that differs between plugins is DATA, not control flow: the
# vendor/ paths that must exist before a zip is trusted, and the self-test
# that proves those dependencies actually work (not merely that the files are
# present). That data lives in PLUGIN_TABLE below. Adding a fifth plugin is
# one line in that table — reusing an existing self-test function if its
# dependency story matches one already covered, or adding one new function if
# it doesn't — never a new `if $PLUGIN == ...` branch in the gates themselves.
#
# A plugin with no production dependencies (entre-redes-campeones today) is a
# first-class entry: empty required-paths, empty self-test. Every other gate
# (composer install, exclude list, artifact inspection, checksum) still runs.
#
# Usage:  ./build-plugin.sh <plugin>            # build
#         ./build-plugin.sh <plugin> --with-dev # restore dev deps afterwards (for phpunit)
#
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Per-plugin data table.
#
# Columns, pipe-separated:
#   1. plugin directory name (== main file basename, == Text Domain)
#   2. required vendor/ paths, comma-separated, relative to the plugin root
#      (empty for a plugin with no production dependencies)
#   3. self-test function name, defined below (empty to skip — only valid
#      together with an empty column 2)
#
# Two plugins can share a self-test function when they depend on the same
# library in the same way: credencial and cambios both only ever VERIFY a
# token signed elsewhere (they never sign one in production), so both point
# at selftest_jwt_verify, which proves the decode+Key path — not
# selftest_jwt_uuid, which is prode's sign+Uuid path.
# ---------------------------------------------------------------------------
PLUGIN_TABLE='
entre-redes-prode|vendor/autoload.php,vendor/firebase/php-jwt/src/JWT.php,vendor/ramsey/uuid/src/Uuid.php|selftest_jwt_uuid
entre-redes-campeones||
entre-redes-credencial|vendor/autoload.php,vendor/firebase/php-jwt/src/JWT.php|selftest_jwt_verify
entre-redes-cambios|vendor/autoload.php,vendor/firebase/php-jwt/src/JWT.php|selftest_jwt_verify
'

# Excluded from every plugin's zip, regardless of its data row: these are
# never production code, and shipping any of them is a different mistake
# than the 0.7.0 one but still not something to upload. Patterns here are
# relative to the plugin root and get the plugin name prefixed on use;
# ".DS_Store" is the one exception (it can appear in any subdirectory, so it
# is matched unprefixed, see the zip invocation below).
EXCLUDES=(
    "tests/*"
    ".git/*"
    ".gitignore"
    ".phpunit.result.cache"
    "phpunit.xml"
)

# ---------------------------------------------------------------------------
# Self-test functions.
#
# Each proves its dependency actually works — loads, signs or verifies a real
# token — not merely that the class exists. A file being present is not proof
# it works; that is exactly how 0.7.0 shipped broken while vendor/ existed in
# nobody's imagination but everyone's assumption.
# ---------------------------------------------------------------------------

# prode signs tokens (JwtService::sign) and also depends on ramsey/uuid.
# Mirrors JwtService::selfTest() without needing a WordPress bootstrap.
selftest_jwt_uuid() {
    local src="$1"
    php -r '
require $argv[1] . "/vendor/autoload.php";
if ( ! class_exists( "Firebase\\JWT\\JWT" ) ) { fwrite( STDERR, "JWT class missing\n" ); exit( 1 ); }
if ( ! class_exists( "Ramsey\\Uuid\\Uuid" ) ) { fwrite( STDERR, "Uuid class missing\n" ); exit( 1 ); }
$k = openssl_pkey_new( [ "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA ] );
openssl_pkey_export( $k, $pem );
$t = Firebase\JWT\JWT::encode( [ "sub" => "1", "exp" => time() + 60 ], $pem, "RS256", "build-check" );
if ( ! is_string( $t ) || "" === $t ) { fwrite( STDERR, "signing produced no token\n" ); exit( 1 ); }
' "$src"
}

# credencial and cambios never sign — both only VERIFY a token issued by
# prode, via Auth\TokenVerifier::verify(), which calls
# JWT::decode($jwt, new Key($publicKeyPem, "RS256")). This self-test signs a
# throwaway token purely as a test fixture (openssl + JWT::encode, the same
# way prode's real JwtService does) and then verifies it through that exact
# decode+Key path, so it proves the half of the library these plugins
# actually ship, not the half they don't.
selftest_jwt_verify() {
    local src="$1"
    php -r '
require $argv[1] . "/vendor/autoload.php";
if ( ! class_exists( "Firebase\\JWT\\JWT" ) ) { fwrite( STDERR, "JWT class missing\n" ); exit( 1 ); }
if ( ! class_exists( "Firebase\\JWT\\Key" ) ) { fwrite( STDERR, "Key class missing\n" ); exit( 1 ); }
$k = openssl_pkey_new( [ "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA ] );
openssl_pkey_export( $k, $private_pem );
$public_pem = openssl_pkey_get_details( $k )["key"];
$token = Firebase\JWT\JWT::encode( [ "sub" => "1", "exp" => time() + 60 ], $private_pem, "RS256", "build-check" );
$decoded = Firebase\JWT\JWT::decode( $token, new Firebase\JWT\Key( $public_pem, "RS256" ) );
if ( ( $decoded->sub ?? null ) !== "1" ) { fwrite( STDERR, "verify roundtrip mismatch\n" ); exit( 1 ); }
' "$src"
}

# ---------------------------------------------------------------------------
# Argument handling.
# ---------------------------------------------------------------------------
available_plugins() {
    awk -F'|' 'NF && $1 != "" {print $1}' <<<"$PLUGIN_TABLE"
}

PLUGIN="${1:-}"
if [[ -z "$PLUGIN" ]]; then
    echo "usage: $(basename "$0") <plugin> [--with-dev]" >&2
    echo "  available plugins:" >&2
    available_plugins | sed 's/^/    /' >&2
    exit 1
fi
shift || true

ROW="$(awk -F'|' -v p="$PLUGIN" '$1==p{print; f=1} END{exit !f}' <<<"$PLUGIN_TABLE")" || {
    echo "error: unknown plugin '$PLUGIN'" >&2
    echo "  available plugins:" >&2
    available_plugins | sed 's/^/    /' >&2
    exit 1
}
IFS='|' read -r _ REQUIRED_PATHS_CSV SELFTEST_FN <<<"$ROW"

SRC="$HERE/$PLUGIN"
[[ -d "$SRC" ]] || { echo "error: $SRC not found" >&2; exit 1; }
command -v composer >/dev/null || { echo "error: composer not on PATH" >&2; exit 1; }

VERSION="$(sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "$SRC/$PLUGIN.php" | head -1)"
[[ -n "$VERSION" ]] || { echo "error: could not read Version from $PLUGIN.php" >&2; exit 1; }

ZIP="$HERE/$PLUGIN-$VERSION.zip"
echo "==> building $PLUGIN $VERSION"

# Clear the target up front, not just before writing. A build that aborts at a
# gate must not leave last week's zip sitting there under the current
# version's name, looking freshly built to whoever uploads it next.
rm -f "$ZIP"

# Production dependency tree only — dev packages (phpunit et al) must never
# ship. Legitimate even when there are zero production dependencies
# (campeones): composer still (re)generates vendor/autoload.php for the
# plugin's own PSR-4 map, and this still prunes phpunit out of the tree.
echo "==> composer install --no-dev"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$SRC" --quiet

# Gate: the exact failure this script exists to prevent. Checked before
# zipping so a broken tree cannot become an uploadable artifact.
if [[ -n "$REQUIRED_PATHS_CSV" ]]; then
    for required in ${REQUIRED_PATHS_CSV//,/ }; do
        [[ -f "$SRC/$required" ]] || {
            echo "error: missing $required — refusing to package" >&2
            exit 1
        }
    done
else
    echo "==> no production dependencies declared — skipping vendor path gate"
fi

# Gate: prove the dependency actually loads and signs/verifies, not merely
# that files exist. A file being present is not proof it works.
if [[ -n "$SELFTEST_FN" ]]; then
    echo "==> verifying the dependency chain ($SELFTEST_FN)"
    "$SELFTEST_FN" "$SRC"
else
    echo "==> no self-test declared — skipping functional gate"
fi

zip_excludes=()
for pattern in "${EXCLUDES[@]}"; do
    zip_excludes+=("$PLUGIN/$pattern")
done
zip_excludes+=("*/.DS_Store")

( cd "$HERE" && zip -r -q "$ZIP" "$PLUGIN" -x "${zip_excludes[@]}" )

# Gate: verify the artifact itself, not the working tree it came from.
# The listing is captured once rather than piped into grep: under `pipefail`,
# `grep -q` exits at the first match, unzip takes SIGPIPE, and the pipeline
# reports failure even though the match was found.
LISTING="$(unzip -l "$ZIP")"

if [[ -n "$REQUIRED_PATHS_CSV" ]]; then
    for required in ${REQUIRED_PATHS_CSV//,/ }; do
        grep -q "$PLUGIN/$required" <<<"$LISTING" || {
            echo "error: zip is missing $required — refusing to publish" >&2
            rm -f "$ZIP"
            exit 1
        }
    done
fi
if grep -q "$PLUGIN/tests/" <<<"$LISTING"; then
    echo "error: zip contains tests/ — refusing to publish" >&2
    rm -f "$ZIP"
    exit 1
fi

echo "==> ok: $ZIP"
echo "    $(tail -1 <<<"$LISTING" | awk '{print $2}') files, $(du -h "$ZIP" | cut -f1)"
echo "    sha256: $(shasum -a 256 "$ZIP" | cut -d' ' -f1)"

# `composer install --no-dev` prunes phpunit, so the test suite cannot run
# until the dev tree is restored. Opt in rather than doing it silently:
# leaving the production tree in place is what keeps a subsequent hand-made
# zip honest.
if [[ "${1:-}" == "--with-dev" ]]; then
    echo "==> restoring dev dependencies"
    composer install --optimize-autoloader --no-interaction --working-dir="$SRC" --quiet
    echo "    dev tree restored — 'composer test' is available again"
else
    echo "    note: dev deps pruned; run with --with-dev to restore phpunit"
fi
