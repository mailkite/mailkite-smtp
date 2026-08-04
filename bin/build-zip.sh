#!/usr/bin/env bash
# Build the distributable plugin zip for WordPress.org.
#
# The zip is NOT the repo. wp.org rejects a submission for files that are perfectly
# normal in a source tree — composer.json, vendor/, phpcs.xml, dotfiles — and Plugin
# Check flags them as errors. This script exports only what ships, which is also the
# tree Plugin Check must be pointed at: scanning the repo instead of dist/ is what
# made an earlier submission look broken when it was not.
#
#   bin/build-zip.sh          # -> dist/<slug>/ and dist/<slug>.zip
set -euo pipefail

SLUG="mailkite-smtp"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/dist"
STAGE="$OUT/$SLUG"

# Everything the plugin needs at runtime, and nothing else.
SHIP=( "$SLUG.php" readme.txt LICENSE uninstall.php src )

VERSION="$(grep -m1 '^ \* Version:' "$ROOT/$SLUG.php" | awk '{print $3}')"
STABLE="$(grep -m1 '^Stable tag:' "$ROOT/readme.txt" | awk '{print $3}')"
if [[ "$VERSION" != "$STABLE" ]]; then
  # These drifting is the classic wp.org release bug: the header says one version,
  # the readme says another, and wp.org serves whichever the Stable tag names.
  echo "error: plugin header version ($VERSION) != readme Stable tag ($STABLE)" >&2
  exit 1
fi

rm -rf "$STAGE" "$OUT/$SLUG.zip"
mkdir -p "$STAGE"
for item in "${SHIP[@]}"; do
  cp -R "$ROOT/$item" "$STAGE/"
done

# Belt and braces: no dev leftovers, no editor droppings, nothing hidden.
find "$STAGE" \( -name '.*' -o -name '*.map' -o -name 'node_modules' -o -name 'vendor' \) -prune -exec rm -rf {} +

( cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG" -x '*.DS_Store' )

echo "built $OUT/$SLUG.zip (v$VERSION)"
unzip -l "$OUT/$SLUG.zip" | tail -1
