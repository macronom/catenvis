#!/usr/bin/env bash
# Sync Catenvis to the deployment server via rsync.
#
# Usage (from anywhere):
#   deploy/deploy.sh            # normal sync
#   deploy/deploy.sh --dry-run  # show what would be transferred
#
# The rsync target comes from CATENVIS_DEPLOY_TARGET (set it in a gitignored
# deploy/deploy.env), so this committed file stays free of private hosts.
# Notes:
# - config/config.php and logs on the server are left untouched.
# - vendor/ is synced along, so no Composer is needed on the server.
# - dot-directories (VCS, caches, editor/tooling state) are not deployed.

set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"

# Optional local settings (gitignored), e.g.:
#   CATENVIS_DEPLOY_TARGET=user@host:/var/www/catenvis/
if [ -f "$SRC/deploy/deploy.env" ]; then
	# shellcheck disable=SC1091
	. "$SRC/deploy/deploy.env"
fi

DEST="${CATENVIS_DEPLOY_TARGET:-<user>@<your-server>:/var/www/catenvis/}"

rsync -az --delete --info=stats1 "$@" \
	--exclude 'config/config.php' \
	--exclude '*.log' \
	--exclude '.*/' \
	"$SRC/" "$DEST"

echo "Deployment to $DEST finished."
