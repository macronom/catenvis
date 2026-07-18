<?php

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;
use Catenvis\EpisodeTitle;

/**
 * Database access to series, episodes and user_series.
 */
final class SeriesRepository {
	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Returns a series including title/description in the requested language
	 * (fallback: English -> original title) as well as the English title (title_en).
	 * Empty names are "not available on TMDB" markers and fall through via NULLIF.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(int $seriesId, string $lang = 'en'): ?array {
		return $this->db->fetchOne(
			"SELECT s.*,
				COALESCE(NULLIF(tul.name, ''), NULLIF(ten.name, ''), s.original_name) AS name,
				COALESCE(NULLIF(tul.overview, ''), ten.overview) AS overview,
				ten.name AS title_en
			 FROM series s
			 LEFT JOIN series_translations tul ON tul.series_id = s.id AND tul.lang = ?
			 LEFT JOIN series_translations ten ON ten.series_id = s.id AND ten.lang = 'en'
			 WHERE s.id = ?",
			[$lang, $seriesId]
		);
	}

	/**
	 * Creates or updates a series (UPSERT).
	 *
	 * @param array<string, mixed> $data Column values incl. id.
	 */
	public function upsertSeries(array $data): void {
		$this->db->execute(
			'INSERT INTO series
				(id, original_name, original_language, imdb_id, first_air_year, poster_path, status, networks,
				 number_of_seasons, number_of_episodes, last_air_date, next_air_date, synced_at)
			 VALUES
				(:id, :original_name, :original_language, :imdb_id, :first_air_year, :poster_path, :status, :networks,
				 :number_of_seasons, :number_of_episodes, :last_air_date, :next_air_date, NOW())
			 ON DUPLICATE KEY UPDATE
				original_name = VALUES(original_name),
				original_language = VALUES(original_language),
				imdb_id = VALUES(imdb_id),
				networks = VALUES(networks),
				first_air_year = VALUES(first_air_year),
				poster_path = VALUES(poster_path),
				status = VALUES(status),
				number_of_seasons = VALUES(number_of_seasons),
				number_of_episodes = VALUES(number_of_episodes),
				last_air_date = VALUES(last_air_date),
				next_air_date = VALUES(next_air_date),
				synced_at = NOW()',
			$data
		);
	}

	/**
	 * Creates or updates a title/description translation (UPSERT).
	 */
	public function upsertTranslation(int $seriesId, string $lang, string $name, ?string $overview): void {
		$this->db->execute(
			'INSERT INTO series_translations (series_id, lang, name, overview)
			 VALUES (?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE name = VALUES(name), overview = VALUES(overview)',
			[$seriesId, $lang, $name, $overview]
		);
	}

	/**
	 * Creates or updates an episode (UPSERT).
	 *
	 * @param array<string, mixed> $data Column values incl. id.
	 */
	public function upsertEpisode(array $data): void {
		$this->db->execute(
			'INSERT INTO episodes
				(id, series_id, season_number, episode_number, air_date)
			 VALUES
				(:id, :series_id, :season_number, :episode_number, :air_date)
			 ON DUPLICATE KEY UPDATE
				air_date = VALUES(air_date),
				season_number = VALUES(season_number),
				episode_number = VALUES(episode_number)',
			$data
		);
	}

	/**
	 * Creates or updates an episode title + description translation (UPSERT).
	 *
	 * INSERT ... SELECT guards against episode ids not stored (yet): a
	 * language fetch may return episodes unknown to our base data, which
	 * would otherwise violate the foreign key.
	 */
	public function upsertEpisodeTranslation(int $episodeId, string $lang, string $name, ?string $overview = null): void {
		$this->db->execute(
			'INSERT INTO episode_translations (episode_id, lang, name, overview)
			 SELECT e.id, ?, ?, ? FROM episodes e WHERE e.id = ?
			 ON DUPLICATE KEY UPDATE name = VALUES(name), overview = VALUES(overview)',
			[$lang, $name, $overview, $episodeId]
		);
	}

	/**
	 * All episodes of a series with a display title resolved via the chain
	 * own language -> English -> base language, ordered by season/episode.
	 * Empty names and TMDB auto-placeholders ("Folge 5") are skipped by
	 * EpisodeTitle::pick, so the returned 'name' is a real title or ''.
	 * 'overview' follows the same own -> English -> base fallback (first
	 * non-empty; descriptions have no placeholder problem).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function episodesForSeries(int $seriesId, string $lang = 'en', string $baseLang = 'en'): array {
		$rows = $this->db->fetchAll(
			"SELECT e.*,
				etu.name AS name_own,
				ete.name AS name_en,
				etb.name AS name_base,
				COALESCE(NULLIF(etu.overview, ''), NULLIF(ete.overview, ''), etb.overview) AS overview
			 FROM episodes e
			 LEFT JOIN episode_translations etu ON etu.episode_id = e.id AND etu.lang = ?
			 LEFT JOIN episode_translations ete ON ete.episode_id = e.id AND ete.lang = 'en'
			 LEFT JOIN episode_translations etb ON etb.episode_id = e.id AND etb.lang = ?
			 WHERE e.series_id = ?
			 ORDER BY e.season_number, e.episode_number",
			[$lang, $baseLang, $seriesId]
		);

		foreach ($rows as &$row) {
			$row['name'] = EpisodeTitle::pick(
				[
					[(string) ($row['name_own'] ?? ''), $lang],
					[(string) ($row['name_en'] ?? ''), 'en'],
					[(string) ($row['name_base'] ?? ''), $baseLang],
				]
			);
			unset($row['name_own'], $row['name_en'], $row['name_base']);
		}
		unset($row);

		return $rows;
	}

	/**
	 * Reports, per season, which of the given languages still lack episode
	 * title rows. Marker rows (name = '') count as covered (anti-loop).
	 *
	 * Single query per series: episodes are joined with the language list
	 * and matched against episode_translations via primary-key lookups.
	 *
	 * @param list<string> $langs Episode languages to check (never empty).
	 * @return array<int, list<string>> season_number => missing languages.
	 */
	public function episodeLanguageCoverage(int $seriesId, array $langs): array {
		$langTable = 'SELECT ? AS lang' . str_repeat(' UNION ALL SELECT ?', count($langs) - 1);
		$rows = $this->db->fetchAll(
			"SELECT e.season_number, l.lang,
				COUNT(*)            AS episode_count,
				COUNT(t.episode_id) AS translated_count
			 FROM episodes e
			 JOIN ($langTable) l
			 LEFT JOIN episode_translations t ON t.episode_id = e.id AND t.lang = l.lang
			 WHERE e.series_id = ? AND e.season_number >= 1
			 GROUP BY e.season_number, l.lang",
			[...array_values($langs), $seriesId]
		);

		$missing = [];
		foreach ($rows as $row) {
			if ((int) $row['translated_count'] < (int) $row['episode_count']) {
				$missing[(int) $row['season_number']][] = (string) $row['lang'];
			}
		}

		return $missing;
	}

	/**
	 * IDs of all series that at least one user is currently following
	 * (deferred ones included).
	 *
	 * @return list<int>
	 */
	public function followedSeriesIds(): array {
		$rows = $this->db->fetchAll(
			"SELECT DISTINCT series_id FROM user_series WHERE status IN ('following','deferred')"
		);

		return array_map(static fn(array $r): int => (int) $r['series_id'], $rows);
	}

	/**
	 * IDs of followed series that should be refreshed today – staggered
	 * by recency to reduce TMDB traffic:
	 *  - running series with upcoming episodes: daily,
	 *  - ended/canceled series: ~every two months (spread over ~2 weeks by ID),
	 *  - all others: ~weekly (spread over a few days by ID).
	 * Also due: series that lack a translation row for an active
	 * language (marker rows with an empty name count as present,
	 * otherwise the series would stay due forever) — for series titles as well as episode titles.
	 *
	 * @param list<string> $seriesLangs  Active content languages (never empty).
	 * @param list<string> $episodeLangs Active episode title languages (never empty).
	 * @return list<int>
	 */
	public function seriesDueForRefresh(array $seriesLangs, array $episodeLangs): array {
		$seriesPh  = implode(',', array_fill(0, count($seriesLangs), '?'));
		$episodePh = implode(',', array_fill(0, count($episodeLangs), '?'));
		$rows = $this->db->fetchAll(
			"SELECT s.id FROM series s
			 WHERE EXISTS (SELECT 1 FROM user_series us
				WHERE us.series_id = s.id AND us.status IN ('following','deferred'))
			   AND (
				 s.synced_at IS NULL
				 OR (SELECT COUNT(*) FROM series_translations t
					WHERE t.series_id = s.id AND t.lang IN ($seriesPh)) < ?
				 -- Episode-title coverage: due when any episode lacks a row for
				 -- an active episode language (PK lookups; EXISTS stops early).
				 OR EXISTS (SELECT 1 FROM episodes e
					WHERE e.series_id = s.id AND e.season_number >= 1
					  AND (SELECT COUNT(*) FROM episode_translations et
						WHERE et.episode_id = e.id AND et.lang IN ($episodePh)) < ?)
				 OR DATEDIFF(CURDATE(), DATE(s.synced_at)) >= (
					CASE
						WHEN (s.next_air_date IS NOT NULL AND s.next_air_date >= CURDATE())
							 OR EXISTS (SELECT 1 FROM episodes e
								WHERE e.series_id = s.id AND e.air_date > CURDATE())
							THEN 1
						WHEN s.status IN ('Ended', 'Canceled')
							THEN 55 + (s.id MOD 14)
						ELSE 7 + (s.id MOD 5)
					END
				 )
			   )
			 ORDER BY s.id",
			[...array_values($seriesLangs), count($seriesLangs),
			 ...array_values($episodeLangs), count($episodeLangs)]
		);

		return array_map(static fn(array $r): int => (int) $r['id'], $rows);
	}

	// --- Follow status per user --------------------------------------------------

	/**
	 * Sets or updates the follow status of a user.
	 *
	 * @param string $status 'following', 'stopped' or 'deferred'.
	 */
	public function setFollowStatus(int $userId, int $seriesId, string $status): void {
		$this->db->execute(
			"INSERT INTO user_series (user_id, series_id, status)
			 VALUES (?, ?, ?)
			 ON DUPLICATE KEY UPDATE status = VALUES(status)",
			[$userId, $seriesId, $status]
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function followStatus(int $userId, int $seriesId): ?array {
		return $this->db->fetchOne(
			'SELECT * FROM user_series WHERE user_id = ? AND series_id = ?',
			[$userId, $seriesId]
		);
	}

	/**
	 * Removes a series for a user: deletes their watched markers and
	 * the follow entry. If nobody follows it afterwards, the series is
	 * completely deleted from the database (cascades episodes), so that no
	 * unnecessary TMDB updates happen any more.
	 *
	 * @return bool True if the series was entirely removed from the DB.
	 */
	public function removeForUser(int $userId, int $seriesId): bool {
		return (bool) $this->db->transaction(function (Database $db) use ($userId, $seriesId): bool {
			$db->execute(
				'DELETE w FROM user_watched w
				 JOIN episodes e ON e.id = w.episode_id
				 WHERE w.user_id = ? AND e.series_id = ?',
				[$userId, $seriesId]
			);
			$db->execute(
				'DELETE FROM user_series WHERE user_id = ? AND series_id = ?',
				[$userId, $seriesId]
			);

			$followers = (int) $db->fetchValue(
				'SELECT COUNT(*) FROM user_series WHERE series_id = ?',
				[$seriesId]
			);
			if ($followers === 0) {
				$db->execute('DELETE FROM series WHERE id = ?', [$seriesId]);
				return true;
			}

			return false;
		});
	}
}
