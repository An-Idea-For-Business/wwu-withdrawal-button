#!/usr/bin/env bash
#
# Build the distributable plugin ZIP.
#
#   - installs production Composer dependencies (Dompdf) into vendor/
#   - copies the plugin into build/wwu-withdrawal-button excluding dev files
#     (see .distignore)
#   - produces dist/wwu-withdrawal-button.zip
#
# Usage:  bash bin/build.sh
#
set -euo pipefail

SLUG="wwu-withdrawal-button"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/build/${SLUG}"
DIST_DIR="${ROOT}/dist"

echo "==> Installing production dependencies (Dompdf)…"
( cd "${ROOT}" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )

echo "==> Preparing build directory…"
rm -rf "${ROOT}/build" "${DIST_DIR}"
mkdir -p "${BUILD_DIR}" "${DIST_DIR}"

# Build an rsync exclude list from .distignore.
EXCLUDES=()
while IFS= read -r line; do
	# skip comments and blank lines
	[[ -z "${line}" || "${line}" =~ ^# ]] && continue
	EXCLUDES+=( "--exclude=${line}" )
done < "${ROOT}/.distignore"

echo "==> Copying plugin files…"
rsync -a "${EXCLUDES[@]}" --exclude='build' --exclude='dist' "${ROOT}/" "${BUILD_DIR}/"

echo "==> Zipping…"
( cd "${ROOT}/build" && zip -rq "${DIST_DIR}/${SLUG}.zip" "${SLUG}" )

echo "==> Done: dist/${SLUG}.zip"
ls -lh "${DIST_DIR}/${SLUG}.zip"
