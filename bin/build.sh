#!/usr/bin/env bash
#
# Build the distributable plugin ZIP (Linux/macOS; bin/build.ps1 is the Windows
# equivalent and the reference implementation — keep the two in sync).
#
# Single source of truth = this branch (PHP 8.1, Dompdf 3.x).
#
#   bash bin/build.sh             PHP 8.1 build -> dist/wwu-withdrawal-button.zip
#   bash bin/build.sh --php74     PHP 7.4 build -> dist/wwu-withdrawal-button-php7.4.zip
#
# --php74 derives the PHP 7.4-compatible artifact from the PHP 8.1 source (no
# separate branch): it rewrites the 5 known version deltas in a backup/restore
# wrapper (composer.json dompdf ^2.0 + php >=7.4 + no platform pin; the plugin
# header Requires PHP / WWU_WB_MIN_PHP; readme Requires PHP), re-resolves Dompdf
# to the 2.x line, builds the -php7.4 zip, and ALWAYS restores the PHP 8.1 source
# afterwards (EXIT trap). It removes only its own target zip, so the sibling 8.1
# zip is preserved — run both back to back at release time.
#
set -euo pipefail

SLUG="wwu-withdrawal-button"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/build/${SLUG}"
DIST_DIR="${ROOT}/dist"

PHP74=0
for arg in "$@"; do
	case "${arg}" in
		--php74) PHP74=1 ;;
		*) echo "Unknown argument: ${arg}" >&2; exit 2 ;;
	esac
done

if [[ "${PHP74}" -eq 1 ]]; then ZIP_NAME="${SLUG}-php7.4.zip"; else ZIP_NAME="${SLUG}.zip"; fi
ZIP="${DIST_DIR}/${ZIP_NAME}"

COMPOSER_F="${ROOT}/composer.json"
LOCK_F="${ROOT}/composer.lock"
BOOT_F="${ROOT}/${SLUG}.php"
README_F="${ROOT}/readme.txt"
BAK_DIR=""

restore_source() {
	# Restore the PHP 8.1 source after a --php74 build (always, even on failure).
	[[ "${PHP74}" -eq 1 && -n "${BAK_DIR}" && -d "${BAK_DIR}" ]] || return 0
	echo "==> [php7.4] Restoring the PHP 8.1 source + Dompdf 3.x…"
	[[ -f "${BAK_DIR}/composer.json" ]] && cp -f "${BAK_DIR}/composer.json" "${COMPOSER_F}"
	[[ -f "${BAK_DIR}/composer.lock" ]] && cp -f "${BAK_DIR}/composer.lock" "${LOCK_F}"
	[[ -f "${BAK_DIR}/bootstrap.php" ]] && cp -f "${BAK_DIR}/bootstrap.php" "${BOOT_F}"
	[[ -f "${BAK_DIR}/readme.txt" ]] && cp -f "${BAK_DIR}/readme.txt" "${README_F}"
	( cd "${ROOT}" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress ) || true
	rm -rf "${BAK_DIR}"
}
trap restore_source EXIT

if [[ "${PHP74}" -eq 1 ]]; then
	echo "==> [php7.4] Deriving the PHP 7.4 build from the PHP 8.1 source…"
	BAK_DIR="$(mktemp -d)"   # outside ROOT so it is never copied into the zip
	cp -f "${COMPOSER_F}" "${BAK_DIR}/composer.json"
	[[ -f "${LOCK_F}" ]] && cp -f "${LOCK_F}" "${BAK_DIR}/composer.lock"
	cp -f "${BOOT_F}" "${BAK_DIR}/bootstrap.php"
	cp -f "${README_F}" "${BAK_DIR}/readme.txt"

	# composer.json -> Dompdf 2.x, php >=7.4, drop the 8.1 platform pin.
	sed -i 's/"php": ">=8.1"/"php": ">=7.4"/' "${COMPOSER_F}"
	sed -i 's#"dompdf/dompdf": "\^3.1"#"dompdf/dompdf": "^2.0"#' "${COMPOSER_F}"
	perl -0pi -e 's/,\s*"platform":\s*\{[^}]*\}//s' "${COMPOSER_F}"

	# Plugin header -> Requires PHP 7.4 + WWU_WB_MIN_PHP 7.4.
	sed -i -E 's/(Requires PHP:[[:space:]]+)8\.1/\17.4/' "${BOOT_F}"
	sed -i "s/WWU_WB_MIN_PHP', '8\.1'/WWU_WB_MIN_PHP', '7.4'/" "${BOOT_F}"

	# readme.txt -> Requires PHP 7.4.
	sed -i -E 's/(Requires PHP:[[:space:]]+)8\.1/\17.4/' "${README_F}"

	# Drop the 8.1 lock so a full resolve picks the 7.4-compatible 2.x line.
	rm -f "${LOCK_F}"
	echo "==> [php7.4] Resolving Dompdf 2.x…"
	( cd "${ROOT}" && composer update --no-dev --optimize-autoloader --no-interaction --no-progress )

	# Safety net: never ship a frankenbuild (3.x vendor + 7.4 declarations).
	DOMPDF_V="$( cd "${ROOT}" && composer show dompdf/dompdf 2>/dev/null | grep -E '^versions' || true )"
	if ! echo "${DOMPDF_V}" | grep -q 'v2\.'; then
		echo "ERROR: [php7.4] expected Dompdf 2.x after resolve, got: ${DOMPDF_V:-<none>} — aborting." >&2
		exit 1
	fi
else
	echo "==> Installing production dependencies (Dompdf)…"
	( cd "${ROOT}" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )
fi

echo "==> Preparing build directory…"
rm -rf "${ROOT}/build"
mkdir -p "${BUILD_DIR}" "${DIST_DIR}"
rm -f "${ZIP}"   # remove only this run's target so the sibling build is preserved

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
( cd "${ROOT}/build" && zip -rq "${ZIP}" "${SLUG}" )

echo "==> Done: dist/${ZIP_NAME}"
ls -lh "${ZIP}"
