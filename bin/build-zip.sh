#!/usr/bin/env bash
#
# build-zip.sh — produce the distributable, installable plugin ZIP.
#
# WordPress installs a plugin into wp-content/plugins/<folder>/, and that folder
# name becomes the plugin slug. It must be "terraviz" (matching the text domain
# and the readme's "upload the terraviz folder" instruction) — which is why we
# stage into dist/terraviz/ by hand rather than using `wp-scripts plugin-zip`
# (that names the inner folder after package.json, i.e. terraviz-wordpress-plugin).
#
# Runs identically locally and in CI. Requires `npm run build` to have populated
# build/ first (the compiled blocks); it does not build for you.
#
# Usage: bin/build-zip.sh   (run from anywhere; resolves the repo root itself)
# Output: dist/terraviz-<version>.zip   (inner top-level folder: terraviz/)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="terraviz"
DIST="dist"
STAGE="$DIST/$SLUG"

# 0. Required tools. rsync ships on CI (ubuntu-latest) and macOS; on a bare Linux
#    dev box install it (e.g. `apt-get install rsync`).
for tool in rsync zip; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "build-zip: '$tool' is required but not installed." >&2
		exit 1
	fi
done

# 1. Refuse to package an internally-inconsistent version.
bash bin/check-version.sh

# 2. Compiled blocks are mandatory — the ZIP is useless without build/.
if [ ! -d build ] || [ -z "$(ls -A build 2>/dev/null)" ]; then
	echo "build-zip: build/ is missing or empty — run 'npm run build' first." >&2
	exit 1
fi

# 3. Derive the version for the archive name (guard above proved it's consistent).
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' terraviz.php | head -n1)"
if [ -z "$VERSION" ]; then
	echo "build-zip: could not determine version from terraviz.php" >&2
	exit 1
fi

# 4. Fresh staging tree.
rm -rf "$DIST"
mkdir -p "$STAGE"

# 5. Copy runtime files, honouring .distignore (single source of truth for
#    exclusions, shared with the future WordPress.org deploy).
rsync -a --exclude-from="$ROOT/.distignore" ./ "$STAGE/"

# 6. Safety net: show exactly what shipped at the top level (visible in CI logs).
echo "build-zip: staged top-level entries in $SLUG/:"
( cd "$STAGE" && ls -A1 | sed 's/^/  /' )

# 7. Zip the staged folder (the terraviz/ prefix inside the archive is the point).
#    -X drops extra file attributes for reproducible archives.
ARCHIVE="$SLUG-$VERSION.zip"
( cd "$DIST" && rm -f "$ARCHIVE" && zip -rqX "$ARCHIVE" "$SLUG" )

echo "build-zip: wrote $DIST/$ARCHIVE"
