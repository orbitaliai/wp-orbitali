#!/usr/bin/env bash
#
# Builds a clean, installable release ZIP of the Orbitali plugin.
# Excludes credentials, dev tooling, and anything not shipped to users.
#
# Usage: bin/build-zip.sh [output-dir]

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="$ROOT_DIR/orbitali"
OUT_DIR="${1:-$ROOT_DIR/build}"

VERSION="$(grep -m1 '^Version:' "$SRC_DIR/orbitali.php" | sed -E 's/^Version:[[:space:]]*//')"
if [ -z "$VERSION" ]; then
	echo "Could not determine plugin version from orbitali.php" >&2
	exit 1
fi

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT

mkdir -p "$OUT_DIR"
cp -R "$SRC_DIR" "$STAGE_DIR/orbitali"

# Dev-only / never-ship paths, even if they somehow exist inside orbitali/.
rm -rf \
	"$STAGE_DIR/orbitali/tests" \
	"$STAGE_DIR/orbitali/.git" \
	"$STAGE_DIR/orbitali/node_modules" \
	"$STAGE_DIR/orbitali/.phpunit.result.cache" \
	"$STAGE_DIR/orbitali/phpunit.xml.dist" \
	"$STAGE_DIR/orbitali/.env" \
	"$STAGE_DIR/orbitali/.env.local"

find "$STAGE_DIR/orbitali" -name '.DS_Store' -delete

ZIP_PATH="$OUT_DIR/orbitali-$VERSION.zip"
rm -f "$ZIP_PATH"

(cd "$STAGE_DIR" && zip -rq "$ZIP_PATH" orbitali)

echo "Built $ZIP_PATH"
