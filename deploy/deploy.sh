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
# - config/ (except config.sample.php) and logs on the server are left
#   untouched; local copies such as config.php.bak are never uploaded.
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

# Files excluded from Git only locally (info/exclude of the repository, also
# found from a linked worktree) stay local as well. rsync reads that file with
# its own rules, which agree with Git's only for plain patterns: '#' comments,
# '*', '?', '[...]', '**' and a leading '/' anchoring at the project root.
# Lines where the two differ would upload or delete the wrong files, so the
# deployment stops on them.
check_local_excludes() {
	local file=$1 line reason number=0
	while IFS= read -r line || [ -n "$line" ]; do
		number=$((number + 1))
		case "$line" in
			'' | '#'*) continue ;;
		esac
		reason=''
		if [[ $line == '!'* ]]; then
			reason="a leading '!' (Git: negation, which rsync does not support)"
		elif [[ $line == ';'* ]]; then
			reason="a leading ';' (rsync: comment, Git: pattern)"
		elif [[ $line == [+-]* ]]; then
			reason="a leading '+' or '-' (rsync: include/exclude rule)"
		elif [[ $line =~ [[:space:]]$ ]]; then
			reason='trailing whitespace (Git ignores it, rsync matches it)'
		elif [[ $line == *'\'* ]]; then
			reason='a backslash (Git and rsync escape differently)'
		fi
		if [ -n "$reason" ]; then
			echo "Deployment stopped: $file, line $number has $reason:" >&2
			echo "  $line" >&2
			echo "Rewrite the line as a plain pattern." >&2
			exit 1
		fi
	done < "$file"
}

LOCAL_EXCLUDES=()
# A checkout (.git as directory, or as file in a linked worktree) must have
# its exclude file honoured: if Git cannot name it (not installed, refused
# "dubious ownership", too old for --path-format), stop rather than upload
# local files. Without .git (deployment from an export) there is none.
if [ -e "$SRC/.git" ]; then
	if ! EXCLUDE_FILE="$(git -C "$SRC" rev-parse --path-format=absolute --git-path info/exclude)" \
		|| [ -z "$EXCLUDE_FILE" ]; then
		echo "Deployment stopped: cannot determine the local Git exclude file of $SRC" >&2
		echo "(see the Git message above). Local files could be uploaded otherwise." >&2
		exit 1
	fi
	if [ -f "$EXCLUDE_FILE" ]; then
		check_local_excludes "$EXCLUDE_FILE"
		LOCAL_EXCLUDES=(--exclude-from "$EXCLUDE_FILE")
	fi
fi

rsync -az --delete --info=stats1 "$@" \
	${LOCAL_EXCLUDES[@]+"${LOCAL_EXCLUDES[@]}"} \
	--include '/config/config.sample.php' \
	--exclude '/config/*' \
	--exclude '*.log' \
	--exclude '.*/' \
	--exclude '/deploy/deploy.env' \
	--exclude '/tests/' \
	--exclude '/.phpunit.result.cache' \
	--exclude '/phpunit.xml.dist' \
	--exclude '/phpstan.neon.dist' \
	"$SRC/" "$DEST"

echo "Deployment to $DEST finished."
