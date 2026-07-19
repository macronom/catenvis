<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;

/**
 * Read-only aggregations for the per-user statistics page: collection counts
 * (series state), watch totals and backlog (viewing data), and a rolling
 * weekly-activity series derived from user_watched.watched_at.
 *
 * Watch time is the sum of per-episode runtimes; episodes without a stored
 * runtime fall back to that series' average of its known runtimes.
 */
final class StatsRepository {
	/** Reusable fallback: per-series average runtime for episodes lacking one. */
	private const AVG_JOIN = 'LEFT JOIN (SELECT series_id, ROUND(AVG(runtime)) AS avg_runtime
			FROM episodes WHERE runtime IS NOT NULL GROUP BY series_id) savg ON savg.series_id = e.series_id';

	/** Minutes of an episode, its series average as fallback. */
	private const MINUTES = 'COALESCE(e.runtime, savg.avg_runtime)';

	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Series counts in two clean dimensions. Follow status
	 * (following/deferred/stopped) partitions ALL of the user's series.
	 * Production status splits the actively followed (following, not deferred)
	 * series, mirroring the dashboard poster badges: returning series into
	 * "airing" (something to watch or a season running - red/violet badge),
	 * "soon" (a new season announced - blue badge) and "idle" (caught up,
	 * nothing scheduled - no badge), plus in-production, ended and canceled.
	 *
	 * @return array{following: int, deferred: int, stopped: int, airing: int, soon: int, idle: int, inproduction: int, ended: int, canceled: int}
	 */
	public function collection(int $userId): array {
		$follow = $this->db->fetchOne(
			"SELECT
				SUM(status = 'following') AS following,
				SUM(status = 'deferred')  AS deferred,
				SUM(status = 'stopped')   AS stopped
			 FROM user_series WHERE user_id = ?",
			[$userId]
		);

		// Per-following-series badge signals (unseen aired count, upcoming
		// count, the next upcoming episode number - E01 = a new season), then
		// bucketed exactly like the dashboard badges.
		$prod = $this->db->fetchOne(
			"SELECT
				SUM(pstatus = 'Returning Series' AND (unseen > 0 OR (upcoming > 0 AND COALESCE(next_ep, 0) <> 1))) AS airing,
				SUM(pstatus = 'Returning Series' AND unseen = 0 AND upcoming > 0 AND next_ep = 1)                   AS soon,
				SUM(pstatus = 'Returning Series' AND unseen = 0 AND upcoming = 0)                                   AS idle,
				SUM(pstatus IN ('In Production','Planned','Pilot'))                                                 AS inproduction,
				SUM(pstatus = 'Ended')                                                                             AS ended,
				SUM(pstatus = 'Canceled')                                                                          AS canceled
			 FROM (
				SELECT s.status AS pstatus,
					(SELECT COUNT(*) FROM episodes e
						WHERE e.series_id = s.id AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
						  AND NOT EXISTS (SELECT 1 FROM user_watched w WHERE w.user_id = ? AND w.episode_id = e.id)) AS unseen,
					(SELECT COUNT(*) FROM episodes e WHERE e.series_id = s.id AND e.air_date > CURDATE()) AS upcoming,
					(SELECT e.episode_number FROM episodes e WHERE e.series_id = s.id AND e.air_date > CURDATE()
						ORDER BY e.air_date, e.season_number, e.episode_number LIMIT 1) AS next_ep
				FROM user_series us
				JOIN series s ON s.id = us.series_id
				WHERE us.user_id = ? AND us.status = 'following'
			 ) t",
			[$userId, $userId]
		);

		return [
			'following'    => (int) ($follow['following'] ?? 0),
			'deferred'     => (int) ($follow['deferred'] ?? 0),
			'stopped'      => (int) ($follow['stopped'] ?? 0),
			'airing'       => (int) ($prod['airing'] ?? 0),
			'soon'         => (int) ($prod['soon'] ?? 0),
			'idle'         => (int) ($prod['idle'] ?? 0),
			'inproduction' => (int) ($prod['inproduction'] ?? 0),
			'ended'        => (int) ($prod['ended'] ?? 0),
			'canceled'     => (int) ($prod['canceled'] ?? 0),
		];
	}

	/**
	 * Total watched episodes and minutes across all of the user's series.
	 *
	 * @return array{episodes: int, minutes: int}
	 */
	public function watchTotals(int $userId): array {
		$row = $this->db->fetchOne(
			'SELECT COUNT(*) AS episodes, COALESCE(SUM(' . self::MINUTES . '), 0) AS minutes
			 FROM user_watched w
			 JOIN episodes e ON e.id = w.episode_id
			 ' . self::AVG_JOIN . '
			 WHERE w.user_id = ?',
			[$userId]
		);

		return ['episodes' => (int) ($row['episodes'] ?? 0), 'minutes' => (int) ($row['minutes'] ?? 0)];
	}

	/**
	 * Unwatched but already-aired episodes of actively followed series
	 * ("still to catch up").
	 *
	 * @return array{episodes: int, minutes: int}
	 */
	public function backlog(int $userId): array {
		$row = $this->db->fetchOne(
			'SELECT COUNT(*) AS episodes, COALESCE(SUM(' . self::MINUTES . '), 0) AS minutes
			 FROM user_series us
			 JOIN episodes e ON e.series_id = us.series_id
				AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
			 ' . self::AVG_JOIN . '
			 WHERE us.user_id = ? AND us.status IN (\'following\',\'deferred\')
			   AND NOT EXISTS (SELECT 1 FROM user_watched w WHERE w.user_id = ? AND w.episode_id = e.id)',
			[$userId, $userId]
		);

		return ['episodes' => (int) ($row['episodes'] ?? 0), 'minutes' => (int) ($row['minutes'] ?? 0)];
	}

	/**
	 * Number of followed series whose every already-aired episode is watched
	 * (and that have at least one aired episode).
	 */
	public function completedSeriesCount(int $userId): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM (
				SELECT s.id
				FROM user_series us
				JOIN series s ON s.id = us.series_id
				JOIN episodes e ON e.series_id = s.id
					AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
				LEFT JOIN user_watched w ON w.episode_id = e.id AND w.user_id = ?
				WHERE us.user_id = ?
				GROUP BY s.id
				HAVING COUNT(*) = COUNT(w.episode_id)
			 ) t',
			[$userId, $userId]
		);
	}

	/**
	 * The user's series ranked by watched time. Returns the fields the shared
	 * $title() helper needs (name/title_en/original_name/original_language).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function topSeriesByTime(int $userId, string $userLang, int $limit = 5, int $offset = 0): array {
		$limit  = max(1, $limit);
		$offset = max(0, $offset);

		return $this->db->fetchAll(
			'SELECT s.id, s.original_name, s.original_language,
				COALESCE(NULLIF(tul.name, \'\'), NULLIF(ten.name, \'\'), s.original_name) AS name,
				ten.name AS title_en,
				agg.episodes, agg.minutes
			 FROM (
				SELECT e.series_id, COUNT(*) AS episodes, COALESCE(SUM(' . self::MINUTES . '), 0) AS minutes
				FROM user_watched w
				JOIN episodes e ON e.id = w.episode_id
				' . self::AVG_JOIN . '
				WHERE w.user_id = :uid
				GROUP BY e.series_id
			 ) agg
			 JOIN series s ON s.id = agg.series_id
			 LEFT JOIN series_translations tul ON tul.series_id = s.id AND tul.lang = :lang
			 LEFT JOIN series_translations ten ON ten.series_id = s.id AND ten.lang = \'en\'
			 ORDER BY agg.minutes DESC, agg.episodes DESC
			 LIMIT ' . $limit . ' OFFSET ' . $offset,
			['uid' => $userId, 'lang' => $userLang]
		);
	}

	/**
	 * Watched minutes per ISO week over the last $weeks weeks (current week
	 * included, missing weeks zero-filled). The user's very first activity day
	 * (the initial bulk import) is excluded so it does not swamp the chart -
	 * derived from the data, no stored baseline needed.
	 *
	 * @return list<array{week_num: int, minutes: int, current: bool}>
	 */
	public function weeklyActivity(int $userId, int $weeks = 8): array {
		$weeks = max(1, $weeks);
		$since = date('Y-m-d 00:00:00', (int) strtotime('monday this week -' . ($weeks - 1) . ' week'));

		$rows = $this->db->fetchAll(
			'SELECT YEARWEEK(w.watched_at, 3) AS yw, COALESCE(SUM(' . self::MINUTES . '), 0) AS minutes
			 FROM user_watched w
			 JOIN episodes e ON e.id = w.episode_id
			 ' . self::AVG_JOIN . '
			 WHERE w.user_id = ?
			   AND w.watched_at >= ?
			   AND DATE(w.watched_at) > (SELECT DATE(MIN(watched_at)) FROM user_watched WHERE user_id = ?)
			 GROUP BY yw',
			[$userId, $since, $userId]
		);
		$map = [];
		foreach ($rows as $r) {
			$map[(int) $r['yw']] = (int) $r['minutes'];
		}

		$out = [];
		for ($i = $weeks - 1; $i >= 0; $i--) {
			$ts  = (int) strtotime('monday this week -' . $i . ' week');
			$iso = (int) date('oW', $ts);
			$out[] = [
				'week_num' => (int) date('W', $ts),
				'minutes'  => $map[$iso] ?? 0,
				'current'  => $i === 0,
			];
		}

		return $out;
	}
}
