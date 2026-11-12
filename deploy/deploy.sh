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
# - vendor/ is synced along, so no Composer is needed on the server. It
#   includes the dev packages (PHPUnit, PHPStan): Composer's autoloader loads
#   some of their files at startup, so they cannot be left out selectively.
#   vendor/ lies outside the web root (html/), so they are not reachable.
# - Not deployed: dot-directories (VCS, caches, editor/tooling state), local
#   notes and settings, tests and the test/analysis configs.
# - Excluded files are never deleted on the server (rsync --delete spares
#   them); remove copies from older deployments there by hand.

set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"

# Optional local settings (gitignored), e.g.:
#   CATENVIS_DEPLOY_TARGET=user@host:/var/www/catenvis/
if [ -f "$SRC/deploy/deploy.env" ]; then
	# shellcheck disable=SC1091
	. "$SRC/deploy/deploy.env"
fi

DEST="${CATENVIS_DEPLOY_TARGET:-<user>@<your-server>:/var/www/catenvis/}"

# Files excluded from Git only locally (.git/info/exclude) stay local as well.
# Its gitignore patterns read the same way as rsync excludes ('#' comments,
# leading '/' anchors at the project root); '!' negations are not supported.
LOCAL_EXCLUDES=()
if [ -f "$SRC/.git/info/exclude" ]; then
	LOCAL_EXCLUDES=(--exclude-from "$SRC/.git/info/exclude")
fi

rsync -az --delete --info=stats1 "$@" \
	${LOCAL_EXCLUDES[@]+"${LOCAL_EXCLUDES[@]}"} \
	--exclude 'config/config.php' \
	--exclude '*.log' \
	--exclude '.*/' \
	--exclude '/deploy/deploy.env' \
	--exclude '/tests/' \
	--exclude '/.phpunit.result.cache' \
	--exclude '/phpunit.xml.dist' \
	--exclude '/phpstan.neon.dist' \
	"$SRC/" "$DEST"

echo "Deployment to $DEST finished."
