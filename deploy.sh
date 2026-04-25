#!/bin/bash
# Deploy local FOSSBilling customizations to the live install.
# Only syncs files tracked by git on the current branch — does not delete live files.

set -euo pipefail

LIVE_DIR="/var/www/fossbilling"
REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC_DIR="$REPO_DIR/src"

echo "Deploying from: $SRC_DIR"
echo "Deploying to:   $LIVE_DIR"
echo ""

# Show what's changed vs. the 0.7.2 tag (i.e. our customizations)
CHANGED=$(git -C "$REPO_DIR" diff --name-only 0.7.2 -- src/ 2>/dev/null | sed 's|^src/||')

if [ -z "$CHANGED" ]; then
  echo "No changes vs. 0.7.2 to deploy."
  exit 0
fi

echo "Files to deploy:"
echo "$CHANGED"
echo ""

read -rp "Proceed? [y/N] " confirm
[[ "$confirm" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }

echo "$CHANGED" | while IFS= read -r file; do
  src="$SRC_DIR/$file"
  dst="$LIVE_DIR/$file"
  if [ -f "$src" ]; then
    mkdir -p "$(dirname "$dst")"
    cp "$src" "$dst"
    echo "  deployed: $file"
  fi
done

echo ""
echo "Done."
