#!/usr/bin/env bash
# Build the installable plugin: only runtime files, no tests, docs or dev tooling.
# Output: build/earlyward/ and build/earlyward-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(sed -n 's/^ \* Version: *//p' earlyward.php | tr -d '\r')"
OUT="build/earlyward"

rm -rf build
mkdir -p "$OUT"
cp earlyward.php readme.txt LICENSE "$OUT/"
[ -f uninstall.php ] && cp uninstall.php "$OUT/"
cp -r src "$OUT/src"
[ -d languages ] && cp -r languages "$OUT/languages"

# Refuse to ship anything that looks private or developmental.
if grep -rIl -E '(/home/[a-z]|@gmail\.com|BEGIN (RSA|OPENSSH) PRIVATE KEY)' "$OUT"; then
	echo "build: refusing to package files that look private (listed above)" >&2
	exit 1
fi

if command -v zip >/dev/null 2>&1; then
	(cd build && zip -qr "earlyward-${VERSION}.zip" earlyward)
	echo "built build/earlyward-${VERSION}.zip"
elif command -v "${PHP:-php}" >/dev/null 2>&1; then
	# No zip tool (e.g. Git Bash on Windows): use PHP's ZipArchive, forward-slash paths.
	"${PHP:-php}" -r '
		$zip = new ZipArchive();
		if ( true !== $zip->open( $argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { exit( 1 ); }
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "build/earlyward", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$path = str_replace( "\\", "/", $file->getPathname() );
			$zip->addFile( $file->getPathname(), substr( $path, strlen( "build/" ) ) );
		}
		exit( $zip->close() ? 0 : 1 );
	' "build/earlyward-${VERSION}.zip"
	echo "built build/earlyward-${VERSION}.zip"
else
	echo "built $OUT (zip not available, archive skipped)"
fi
