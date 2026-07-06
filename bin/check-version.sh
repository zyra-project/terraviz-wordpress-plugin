#!/usr/bin/env bash
#
# check-version.sh — assert the plugin version is identical everywhere it lives.
#
# The version string is duplicated across several files that WordPress, the
# WordPress.org directory, and the build tooling each read independently. If they
# drift, WP.org can publish the wrong version, the update mechanism misbehaves, or
# a block ships stamped stale. This guard collects every copy and fails if they
# disagree. Run it in CI (Node-bearing job) and as a precondition of build-zip.sh.
#
# Sources checked:
#   1. terraviz.php  header  "Version:"
#   2. terraviz.php  constant TERRAVIZ_VERSION
#   3. readme.txt    "Stable tag:"
#   4. package.json  "version"
#   5. blocks/*/block.json  "version"  (each)
#
# Needs Node (for JSON parsing) — do not call from the PHP-only CI job.
#
# Usage: bin/check-version.sh   (run from anywhere; resolves the repo root itself)
# Exits 0 and prints the agreed version on success; prints a source→value table
# and exits 1 on any mismatch.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail=0
# Parallel arrays: source label -> extracted value.
labels=()
values=()

record() {
	labels+=("$1")
	values+=("$2")
}

require() {
	# require <label> <value> — flag empty extractions as an error, not "".
	if [ -z "$2" ]; then
		echo "check-version: could not extract version for: $1" >&2
		fail=1
	fi
	record "$1" "$2"
}

# 1. terraviz.php header "Version:" (doc-block line: " * Version:   0.1.0")
header_ver="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' terraviz.php | head -n1)"
require "terraviz.php (Version: header)" "$header_ver"

# 2. terraviz.php constant define( 'TERRAVIZ_VERSION', '0.1.0' )
const_ver="$(sed -n "s/.*define([[:space:]]*'TERRAVIZ_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" terraviz.php | head -n1)"
require "terraviz.php (TERRAVIZ_VERSION)" "$const_ver"

# 3. readme.txt "Stable tag:"
stable_ver="$(sed -n 's/^Stable tag:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' readme.txt | head -n1)"
require "readme.txt (Stable tag)" "$stable_ver"

# 4. package.json "version"
pkg_ver="$(node -p "require('./package.json').version" 2>/dev/null || true)"
require "package.json (version)" "$pkg_ver"

# 5. blocks/*/block.json "version" (each)
for bj in blocks/*/block.json; do
	[ -e "$bj" ] || continue
	block_ver="$(node -p "require('./$bj').version || ''" 2>/dev/null || true)"
	require "$bj (version)" "$block_ver"
done

# All distinct non-empty values.
distinct="$(printf '%s\n' "${values[@]}" | grep -v '^$' | sort -u)"
count="$(printf '%s\n' "$distinct" | grep -c . || true)"

if [ "$fail" -ne 0 ] || [ "$count" -ne 1 ]; then
	echo "check-version: FAIL — version strings disagree:" >&2
	for i in "${!labels[@]}"; do
		printf '  %-40s %s\n' "${labels[$i]}" "${values[$i]:-<empty>}" >&2
	done
	exit 1
fi

echo "check-version: OK — all sources agree on $distinct"
