<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;

/**
 * Database access to user_watched and derived progress queries.
 */
final class WatchRepository {
	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Number of a user's series with the given follow status;
	 * 'following' includes deferred series.
	 */
	public function countByFollowStatus(int $userId, string $status): int {
		if ($status === 'following') {
			return (int) $this->db->fetchValue(
				"SELECT COUNT(*) FROM user_series WHERE user_id = ? AND status IN ('following','deferred')",
				[$userId]
			);
		}

		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM user_series WHERE user_id = ? AND status = ?',
			[$userId, $status]
		);
	}

	/**
	 * A (optionally limited) page of a user's series with progress data.
	 *
	 * @param string   $sort   'name' sorts alphabetically, otherwise in groups
	 *                          (1: unseen episodes, 2: coming soon, 3: deferred, 4: rest).
	 *                          Groups 1, 3 & 4: most recently aired episode first;
	 *                          group 2: next upcoming airing first.
	 * @param string   $status Follow status; 'following' also covers deferred
	 *                          series ('deferred'), 'stopped' only stopped ones.
	 * @param int|null $limit  Maximum count (null = unlimited).
	 * @param int      $offset Start offset for the pagination.
	 * @param string   $titleLang ISO code of the own language or 'original' (title/sort mode).
	 * @param string   $userLang  Content language of the user (for JOIN and fallback).
	 * @param string   $baseLang  Base language of the TMDB data (episode title fallback).
	 * @return list<array<string, mixed>>
	 */
	public function seriesPage(int $userId, string $sort, string $status, ?int $limit = null, int $offset = 0, string $titleLang = 'en', string $userLang = 'en', string $baseLang = 'en'): array {
		// Whitelist – no user input directly in ORDER BY.
		// Group 2 = "coming soon": more episodes announced OR no episode aired yet.
		// Deferred series form group 3 – behind the announced ones, before the rest.
		$isSoon    = 'unseen_count = 0 AND (aired_count = 0 OR upcoming_count > 0 OR s.next_air_date IS NOT NULL)';
		$groupRank = "CASE WHEN us.status = 'deferred' THEN 3 WHEN unseen_count > 0 THEN 1 WHEN $isSoon THEN 2 ELSE 4 END";
		// Next airing: TMDB field, otherwise the earliest future episode.
		$nextKey   = 'COALESCE(s.next_air_date, next_ep)';
		// Most recent actually aired episode (from episodes), otherwise the TMDB field.
		// The series field last_air_date is often NULL or stale.
		$lastAiredKey = 'COALESCE(last_aired, s.last_air_date)';
		// In the rest group: still-running series before ended/canceled ones.
		$statusRank = "CASE WHEN s.status IN ('Returning Series','In Production','Planned','Pilot') THEN 0 ELSE 1 END";
		// Sort title per language preference. Titles come from series_translations
		// (tul = the user's language, ten = 'en'); empty names are markers
		// "not available on TMDB" and fall through via NULLIF.
		$userTitle = "COALESCE(NULLIF(tul.name, ''), NULLIF(ten.name, ''), s.original_name)";
		$titleExpr = $titleLang === 'original'
			? "CASE WHEN s.original_language = :lang_title THEN $userTitle
				WHEN ten.name IS NOT NULL AND ten.name <> '' THEN ten.name
				WHEN s.original_name <> '' THEN s.original_name
				ELSE COALESCE(tul.name, '') END"
			: $userTitle;

		$defaultOrder = $groupRank
			// Group 2: earliest upcoming airing first, series without a date go to the end.
			. ", CASE WHEN $isSoon THEN ($nextKey IS NULL) END ASC"
			. ", CASE WHEN $isSoon THEN $nextKey END ASC"
			// Rest group: running ones at the top, ended/canceled at the bottom.
			. ", CASE WHEN unseen_count = 0 AND NOT ($isSoon) THEN $statusRank END ASC"
			// Groups 1 & 3: most recently aired episode first.
			. ", CASE WHEN NOT ($isSoon) THEN $lastAiredKey END DESC"
			. ", $titleExpr";

		$orderBy = $sort === 'name' ? $titleExpr : $defaultOrder;

		// LIMIT/OFFSET are inserted as validated integers (native prepares
		// do not allow bound parameters here).
		$limitSql = '';
		if ($limit !== null) {
			$limitSql = ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);
		}

		// The "following" section also covers deferred series.
		$statusCond = $status === 'following'
			? "us.status IN ('following','deferred')"
			: 'us.status = :status';
		$params = [
			'lang_user'        => $userLang,
			'lang_ep_user'     => $userLang,
			'lang_ep_base'     => $baseLang,
			'uid_watched'      => $userId,
			'uid_unseen'       => $userId,
			'uid_next_season'  => $userId,
			'uid_next_episode' => $userId,
			'uid_next_id'      => $userId,
			'uid_next_name'    => $userId,
			'uid_where'        => $userId,
		];
		// Native prepares do not allow surplus parameters – :lang_title
		// only exists in the original mode of the sort title.
		if ($titleLang === 'original') {
			$params['lang_title'] = $userLang;
		}
		if ($status !== 'following') {
			$params['status'] = $status;
		}

		return $this->db->fetchAll(
			'SELECT s.*, s.status AS series_status, us.status,
				COALESCE(NULLIF(tul.name, \'\'), NULLIF(ten.name, \'\'), s.original_name) AS name,
				ten.name AS title_en,
				(SELECT COUNT(*) FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()) AS aired_count,
				(SELECT COUNT(*) FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date > CURDATE()) AS upcoming_count,
				(SELECT MIN(e.air_date) FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date > CURDATE()) AS next_ep,
				(SELECT MAX(e.air_date) FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()) AS last_aired,
				(SELECT COUNT(*) FROM episodes e
					JOIN user_watched w ON w.episode_id = e.id AND w.user_id = :uid_watched
					WHERE e.series_id = s.id) AS watched_count,
				(SELECT COUNT(*) FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
					  AND NOT EXISTS (SELECT 1 FROM user_watched w
						WHERE w.user_id = :uid_unseen AND w.episode_id = e.id)) AS unseen_count,
				(SELECT e.season_number FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
					  AND NOT EXISTS (SELECT 1 FROM user_watched w
						WHERE w.user_id = :uid_next_season AND w.episode_id = e.id)
					ORDER BY e.season_number, e.episode_number LIMIT 1) AS next_unseen_season,
				(SELECT e.episode_number FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
					  AND NOT EXISTS (SELECT 1 FROM user_watched w
						WHERE w.user_id = :uid_next_episode AND w.episode_id = e.id)
					ORDER BY e.season_number, e.episode_number LIMIT 1) AS next_unseen_episode,
				(SELECT e.id FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
					  AND NOT EXISTS (SELECT 1 FROM user_watched w
						WHERE w.user_id = :uid_next_id AND w.episode_id = e.id)
					ORDER BY e.season_number, e.episode_number LIMIT 1) AS next_unseen_id,
				(SELECT COALESCE(NULLIF(etu.name, \'\'), NULLIF(etb.name, \'\')) FROM episodes e
					LEFT JOIN episode_translations etu ON etu.episode_id = e.id AND etu.lang = :lang_ep_user
					LEFT JOIN episode_translations etb ON etb.episode_id = e.id AND etb.lang = :lang_ep_base
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
					  AND NOT EXISTS (SELECT 1 FROM user_watched w
						WHERE w.user_id = :uid_next_name AND w.episode_id = e.id)
					ORDER BY e.season_number, e.episode_number LIMIT 1) AS next_unseen_name,
				(SELECT e.episode_number FROM episodes e
					WHERE e.series_id = s.id
					  AND e.air_date IS NOT NULL AND e.air_date > CURDATE()
					ORDER BY e.air_date, e.season_number, e.episode_number LIMIT 1) AS next_up_episode
			 FROM user_series us
			 JOIN series s ON s.id = us.series_id
			 LEFT JOIN series_translations tul ON tul.series_id = s.id AND tul.lang = :lang_user
			 LEFT JOIN series_translations ten ON ten.series_id = s.id AND ten.lang = \'en\'
			 WHERE us.user_id = :uid_where AND ' . $statusCond . '
			 ORDER BY ' . $orderBy . $limitSql,
			$params
		);
	}

	/**
	 * Watched episode IDs of a user for a series.
	 *
	 * @return list<int>
	 */
	public function watchedEpisodeIds(int $userId, int $seriesId): array {
		$rows = $this->db->fetchAll(
			'SELECT w.episode_id FROM user_watched w
			 JOIN episodes e ON e.id = w.episode_id
			 WHERE w.user_id = ? AND e.series_id = ?',
			[$userId, $seriesId]
		);

		return array_map(static fn(array $r): int => (int) $r['episode_id'], $rows);
	}

	/**
	 * Marks a single episode as watched.
	 */
	public function markEpisode(int $userId, int $episodeId): void {
		$this->db->execute(
			'INSERT IGNORE INTO user_watched (user_id, episode_id) VALUES (?, ?)',
			[$userId, $episodeId]
		);
	}

	/**
	 * Removes the watched marker of a single episode.
	 */
	public function unmarkEpisode(int $userId, int $episodeId): void {
		$this->db->execute(
			'DELETE FROM user_watched WHERE user_id = ? AND episode_id = ?',
			[$userId, $episodeId]
		);
	}

	/**
	 * Marks all already aired episodes of a season as watched.
	 */
	public function markSeason(int $userId, int $seriesId, int $seasonNumber): void {
		$this->db->execute(
			'INSERT IGNORE INTO user_watched (user_id, episode_id)
			 SELECT ?, e.id FROM episodes e
			 WHERE e.series_id = ? AND e.season_number = ?
			   AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()',
			[$userId, $seriesId, $seasonNumber]
		);
	}

	/**
	 * Removes the watched marker of all episodes of a season.
	 */
	public function unmarkSeason(int $userId, int $seriesId, int $seasonNumber): void {
		$this->db->execute(
			'DELETE w FROM user_watched w
			 JOIN episodes e ON e.id = w.episode_id
			 WHERE w.user_id = ? AND e.series_id = ? AND e.season_number = ?',
			[$userId, $seriesId, $seasonNumber]
		);
	}

	/**
	 * Marks all already aired episodes of a series as watched.
	 */
	public function markSeries(int $userId, int $seriesId): void {
		$this->db->execute(
			'INSERT IGNORE INTO user_watched (user_id, episode_id)
			 SELECT ?, e.id FROM episodes e
			 WHERE e.series_id = ?
			   AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()',
			[$userId, $seriesId]
		);
	}

	/**
	 * Marks all episodes of completed seasons as watched for the import.
	 *
	 * Only seasons in which every episode has already aired are marked.
	 * Seasons with still-upcoming (or undated) episodes stay untouched,
	 * so that running seasons can still be tracked manually. For
	 * completed series this marks the entire series.
	 */
	public function markCompletedSeasons(int $userId, int $seriesId): void {
		$this->db->execute(
			'INSERT IGNORE INTO user_watched (user_id, episode_id)
			 SELECT ?, e.id FROM episodes e
			 WHERE e.series_id = ?
			   AND e.air_date IS NOT NULL AND e.air_date <= CURDATE()
			   AND e.season_number NOT IN (
				   SELECT e2.season_number FROM episodes e2
				   WHERE e2.series_id = ?
					 AND (e2.air_date IS NULL OR e2.air_date > CURDATE())
			   )',
			[$userId, $seriesId, $seriesId]
		);
	}

	/**
	 * Removes the watched marker of all episodes of a series.
	 */
	public function unmarkSeries(int $userId, int $seriesId): void {
		$this->db->execute(
			'DELETE w FROM user_watched w
			 JOIN episodes e ON e.id = w.episode_id
			 WHERE w.user_id = ? AND e.series_id = ?',
			[$userId, $seriesId]
		);
	}
}
