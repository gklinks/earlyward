#!/usr/bin/env bash
# Build the installable plugin: only runtime files, no tests, docs or dev tooling.
# Output: build/wallwarden/ and build/wallwarden-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(sed -n 's/^ \* Version: *//p' wallwarden.php | tr -d '\r')"
OUT="build/wallwarden"

rm -rf build
mkdir -p "$OUT"
cp wallwarden.php readme.txt LICENSE "$OUT/"
[ -f uninstall.php ] && cp uninstall.php "$OUT/"
cp -r src "$OUT/src"

# Refuse to ship anything that looks private or developmental.
if grep -rIl -E '(/home/[a-z]|@gmail\.com|BEGIN (RSA|OPENSSH) PRIVATE KEY)' "$OUT"; then
	echo "build: refusing to package files that look private (listed above)" >&2
	exit 1
fi

if command -v zip >/dev/null 2>&1; then
	(cd build && zip -qr "wallwarden-${VERSION}.zip" wallwarden)
	echo "built build/wallwarden-${VERSION}.zip"
else
	echo "built $OUT (zip not available, archive skipped)"
fi
