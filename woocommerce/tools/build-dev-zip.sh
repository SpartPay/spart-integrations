#!/usr/bin/env bash
# Build a local DEV-ONLY zip of the spart-woocommerce plugin for manual smoke
# testing in a real WordPress instance. NOT a production build — PR5 will
# ship the production builder (.pot generation, escape-late checks, readme.txt,
# release CI job).
#
# Output: woocommerce/dist/spart-woocommerce.zip
# Host requirements: rsync, zip, docker. PHP/Composer NOT required.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$(cd "${SCRIPT_DIR}/.." && pwd)"
# The PHP SDK lives in the public spartpay/spart-sdks repo, expected as a
# sibling checkout of this repo (matching the composer.json path repo
# ../../spart-sdks/php). Allow an explicit override via SDK_SRC.
SDK_SRC="${SDK_SRC:-${PLUGIN_SRC}/../../spart-sdks/php}"
if [ ! -d "${SDK_SRC}" ]; then
  echo "ERROR: SDK source not found at '${SDK_SRC}'." >&2
  echo "Clone spartpay/spart-sdks as a sibling of this repository, or set SDK_SRC to its php/ folder." >&2
  exit 1
fi
SDK_SRC="$(cd "${SDK_SRC}" && pwd)"
DIST_DIR="${PLUGIN_SRC}/dist"

for cmd in rsync zip docker; do
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    echo "ERROR: required command '${cmd}' not found in PATH" >&2
    exit 1
  fi
done

# /tmp avoids macOS Docker Desktop's privacy prompt for /Users/ paths.
STAGING_ROOT="/tmp/spart-wc-zip-build"
rm -rf "${STAGING_ROOT}"
STAGING="${STAGING_ROOT}/spart-woocommerce"
SDK_MIRROR="${STAGING_ROOT}/sdk-mirror"
mkdir -p "${STAGING}" "${SDK_MIRROR}" "${DIST_DIR}"

echo "==> Copying plugin source (excluding dev artefacts)..."
rsync -a \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='dist/' \
  --exclude='tools/' \
  --exclude='.gitignore' \
  --exclude='.wp-env*.json' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='*.dist' \
  --exclude='phpstan-bootstrap.php' \
  --exclude='.phpunit.cache/' \
  --exclude='.phpunit.result.cache' \
  --exclude='.DS_Store' \
  "${PLUGIN_SRC}/" "${STAGING}/"

echo "==> Mirroring SDK source (excluding SDK dev artefacts)..."
rsync -a \
  --exclude='vendor/' \
  --exclude='tests/' \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='phpunit*.xml' \
  --exclude='phpstan*.neon' \
  --exclude='phpcs*.xml' \
  --exclude='composer.lock' \
  --exclude='.DS_Store' \
  "${SDK_SRC}/" "${SDK_MIRROR}/"

echo "==> Rewriting composer.json (+ composer.lock if present) path repo + installing runtime deps via Docker..."
# composer.lock (when committed) embeds the path-repo URL under each
# path-typed package's dist.url / source.url and takes precedence over
# composer.json, so we rewrite it too. The plugin does not ship a lock,
# so it is treated as optional and `composer install` resolves spart/sdk
# from the in-container mirror via the rewritten composer.json.
docker run --rm \
  -v "${STAGING_ROOT}:/build" \
  -w /build/spart-woocommerce \
  cimg/php:8.1 sh -c '
    set -e
    sed -i "s|../../spart-sdks/php|/build/sdk-mirror|g" composer.json
    if [ -f composer.lock ]; then
      sed -i "s|../../spart-sdks/php|/build/sdk-mirror|g" composer.lock
    fi
    sed -i "s|\"symlink\": true|\"symlink\": false|" composer.json
    composer install --no-dev --no-interaction --no-progress --optimize-autoloader
  '

# Even with \"symlink\": false, some composer versions still symlink path
# repos. Guarantee a real directory so the zip is portable.
if [ -L "${STAGING}/vendor/spart/sdk" ]; then
  echo "==> Replacing composer-created SDK symlink with a real copy..."
  rm "${STAGING}/vendor/spart/sdk"
  mkdir -p "${STAGING}/vendor/spart/sdk"
  rsync -a "${SDK_MIRROR}/" "${STAGING}/vendor/spart/sdk/"
fi

# Rewrite the staging-only Docker path that composer recorded in vendor
# metadata back to the package-relative location so it doesn't ship.
if [ -d "${STAGING}/vendor/composer" ]; then
  find "${STAGING}/vendor/composer" -maxdepth 1 -type f -name '*.json' \
    -exec sed -i.bak 's|/build/sdk-mirror|sdk|g' {} \;
  find "${STAGING}/vendor/composer" -maxdepth 1 -type f -name '*.bak' -delete
fi

# Drop manifests that reference the dev-only path repo or have no use in WP.
rm -f "${STAGING}/composer.json" "${STAGING}/composer.lock"

echo "==> Privacy guard..."
# /build/ catches absolute Docker staging paths that composer can record in
# vendor metadata (e.g., installed.json, installed.php).
LEAKS="$(grep -rn -E 'mizrael|github\.com/mizrael|/Users/|/build/' "${STAGING}" \
  --include='*.php' --include='*.json' --include='*.xml' --include='*.neon' \
  --include='*.txt' --include='*.md' --include='*.lock' 2>/dev/null || true)"
if [ -n "${LEAKS}" ]; then
  echo "PRIVACY VIOLATION: forbidden strings in zip staging:" >&2
  echo "${LEAKS}" >&2
  exit 1
fi

ZIP_PATH="${DIST_DIR}/spart-woocommerce.zip"
rm -f "${ZIP_PATH}"

echo "==> Zipping..."
( cd "${STAGING_ROOT}" && zip -rq "${ZIP_PATH}" spart-woocommerce )

echo ""
echo "Built: ${ZIP_PATH}"
ls -lh "${ZIP_PATH}"
echo ""
echo "Top-level contents (first 25 entries):"
unzip -l "${ZIP_PATH}" | head -25
