#!/bin/sh
# Builds dist/smartsite-seo-bridge.zip with the plugin files only (no examples, docs or git data).
set -e
cd "$(dirname "$0")"
OUT="$(pwd)/dist/smartsite-seo-bridge.zip"
STAGE="$(mktemp -d)"
mkdir -p "$STAGE/smartsite-seo-bridge" dist
cp -R smartsite-seo-bridge.php uninstall.php includes assets bundled "$STAGE/smartsite-seo-bridge/"
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" smartsite-seo-bridge)
echo "Built $OUT"
