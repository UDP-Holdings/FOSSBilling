#!/usr/bin/env bash
# Deploy UDP-custom FOSSBilling files to the live udp.social server.
#
# Collects every file under src/ that differs from upstream 0.7.2 tag,
# has uncommitted working-tree changes, or is an untracked addition,
# then rsyncs them to /var/www/fossbilling on the live VPS.
#
# Usage:
#   ./deploy.sh              — deploy
#   ./deploy.sh --dry-run    — print file list without touching the server

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSH_KEY="$HOME/.ssh/udp_admin"
VPS="root@udp.social"
REMOTE_DIR="/var/www/fossbilling"
DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

SSH="ssh -i $SSH_KEY"

step() { echo; echo "▸ $*"; }
ok()   { echo "  ✓ $*"; }

# ── Collect file list ──────────────────────────────────────────────────────────
# Files committed on our branch that differ from upstream 0.7.2
committed=$(git -C "$REPO" diff --name-only 0.7.2 -- src/ 2>/dev/null | sed 's|^src/||' || true)

# Tracked files with uncommitted working-tree changes under src/
dirty=$(git -C "$REPO" diff --name-only HEAD -- src/ 2>/dev/null | sed 's|^src/||' || true)

# Untracked new files under src/
untracked=$(git -C "$REPO" ls-files --others --exclude-standard -- src/ 2>/dev/null | sed 's|^src/||' || true)

all_files=$(printf '%s\n%s\n%s\n' "$committed" "$dirty" "$untracked" \
  | sort -u \
  | grep -v '^$' \
  | grep -v '^data/cache/')

if [[ -z "$all_files" ]]; then
  echo "Nothing to deploy (no diff from 0.7.2 tag)."
  exit 0
fi

echo "Files to deploy:"
echo "$all_files" | sed 's/^/  /'
echo ""

$DRY_RUN && echo "(dry run — no files transferred; build artifacts would also be synced)" && exit 0

# ── Sync PHP/template files ────────────────────────────────────────────────────
step "Syncing $(echo "$all_files" | wc -l | tr -d ' ') files..."
list_file=$(mktemp)
trap 'rm -f "$list_file"' EXIT
echo "$all_files" > "$list_file"
rsync -az --checksum --files-from="$list_file" -e "$SSH" "$REPO/src/" "$VPS:$REMOTE_DIR/"
ok "done"

# ── Theme build artifacts (gitignored, always sync) ───────────────────────────
step "Syncing theme build artifacts..."
rsync -az --checksum --delete -e "$SSH" \
  "$REPO/src/themes/udp/assets/build/" \
  "$VPS:$REMOTE_DIR/themes/udp/assets/build/"
ok "themes/udp/assets/build/ synced"

# ── Ownership + cache + installer ─────────────────────────────────────────────
step "Post-deploy cleanup..."
$SSH "$VPS" bash -s <<ENDSSH
chown -R fossbilling:www-data $REMOTE_DIR
find $REMOTE_DIR/data/cache -mindepth 1 -maxdepth 1 -type d ! -name 'sf_cache' -exec rm -rf {} +
if [ -d $REMOTE_DIR/install ]; then
  rm -rf $REMOTE_DIR/install
  echo "  removed: install/"
else
  echo "  install/ already absent"
fi
ENDSSH
ok "ownership fixed, cache cleared"

echo ""
echo "Done — https://billing.udp.social"
