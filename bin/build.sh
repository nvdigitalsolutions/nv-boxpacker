#!/usr/bin/env bash
# =============================================================================
# build.sh — Production build script for FunnelKit USPS Priority Shipping Optimizer
#
# Usage:
#   bash bin/build.sh [version]
#
# This script:
#   1. Installs production Composer dependencies (no dev packages).
#   2. Creates a distributable ZIP archive excluding development files.
#
# The resulting ZIP is ready for upload to WordPress.org or manual installation.
# =============================================================================

set -euo pipefail

PLUGIN_SLUG="woocommerce-fk-usps-optimizer"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${PLUGIN_DIR}/build"
VERSION="${1:-$(grep "Version:" "${PLUGIN_DIR}/woocommerce-fk-usps-optimizer.php" | awk '{print $NF}')}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Building ${PLUGIN_SLUG} v${VERSION}"

# ---- 1. Install production-only dependencies --------------------------------
echo "==> Installing production Composer dependencies..."
composer install \
  --working-dir="${PLUGIN_DIR}" \
  --no-dev \
  --optimize-autoloader \
  --no-interaction \
  --quiet

# ---- 2. Prepare build directory ---------------------------------------------
echo "==> Preparing build directory..."
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"

# ---- 3. Copy plugin files (respecting .distignore) --------------------------
echo "==> Copying plugin files..."
rsync -a \
  --exclude-from="${PLUGIN_DIR}/.distignore" \
  "${PLUGIN_DIR}/" \
  "${BUILD_DIR}/${PLUGIN_SLUG}/"

# ---- 4. Create ZIP archive --------------------------------------------------
echo "==> Creating ZIP archive: ${ZIP_NAME}"
cd "${BUILD_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/"
cd "${PLUGIN_DIR}"

echo "==> Build complete: build/${ZIP_NAME}"

# ---- 5. Restore dev dependencies for local development ----------------------
echo "==> Restoring dev dependencies..."
composer install \
  --working-dir="${PLUGIN_DIR}" \
  --no-interaction \
  --quiet

echo "==> Done."
