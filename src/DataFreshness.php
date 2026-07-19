<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * Decides whether the cached TMDB data has gone stale, i.e. whether the
 * nightly refresh has stopped succeeding altogether (dead cron, broken API
 * key, TMDB down, failing writes).
 *
 * Freshness is derived from the most recent successful series sync
 * (MAX(series.synced_at)) rather than a dedicated heartbeat: whatever the
 * cause, if nothing has synced for a while the data is stale by definition -
 * and if something keeps syncing (by any path) it is genuinely fresh.
 */
final class DataFreshness {
	/**
	 * Number of whole days the newest data is behind, or null when the data is
	 * still fresh (age below the threshold), its age cannot be determined
	 * (no sync yet / unparseable timestamp), or the check is disabled
	 * (threshold below 1).
	 *
	 * @param string|null $lastSyncAt    Most recent successful sync timestamp
	 *                                   (MAX(series.synced_at)), or null.
	 * @param string      $now           Current timestamp (same clock/zone).
	 * @param int         $thresholdDays Age from which the data counts as stale.
	 */
	public static function staleDays(?string $lastSyncAt, string $now, int $thresholdDays): ?int {
		if ($thresholdDays < 1 || $lastSyncAt === null) {
			return null;
		}

		$last    = strtotime($lastSyncAt);
		$current = strtotime($now);
		if ($last === false || $current === false) {
			return null;
		}

		$days = (int) floor(($current - $last) / 86400);

		return $days >= $thresholdDays ? $days : null;
	}
}
