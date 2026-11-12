<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

use Catenvis\Repository\SeriesRepository;

/**
 * Fetches series/episode data from the TMDB API and stores it in the database.
 * Used both when adding a series and by the daily cron.
 */
final class SeriesService {
	private TmdbApi $tmdb;
	private SeriesRepository $series;
	/** @var list<string> Active series content languages (always contains the base language and 'en'). */
	private array $languages;
	/** @var list<string> Active episode title languages (base language plus non-admin user prefs). */
	private array $episodeLanguages;
	/** ISO code of the TMDB base request language (top-level payload fields). */
	private string $baseLang;

	/** Largest storable episode id (episodes.id INT UNSIGNED). */
	private const MAX_EPISODE_ID = 4294967295;
	/** Largest storable season number (SMALLINT UNSIGNED). */
	private const MAX_SEASON_NUMBER = 65535;
	/** Network names are cut to this many characters (bounds the JSON column). */
	private const NETWORK_NAME_LENGTH = 100;

	/**
	 * @param list<string> $languages        Active series content languages.
	 * @param list<string> $episodeLanguages Active episode title languages.
	 */
	public function __construct(TmdbApi $tmdb, SeriesRepository $series, array $languages = ['en'], array $episodeLanguages = ['en']) {
		$this->tmdb             = $tmdb;
		$this->series           = $series;
		$this->languages        = $languages;
		$this->episodeLanguages = $episodeLanguages;
		$this->baseLang         = $tmdb->baseLanguageCode();
	}

	/**
	 * Synchronizes a series along with its (changed) seasons from TMDB.
	 *
	 * @param bool $forceSeasons Re-fetch every season in every active language,
	 *                           even when title coverage is already complete
	 *                           (one-time backfill, e.g. of episode overviews).
	 * @return int Number of seasons reloaded from TMDB.
	 */
	public function sync(int $seriesId, bool $forceSeasons = false): int {
		try {
			$details = $this->tmdb->tvDetails($seriesId);
		} catch (TmdbException $e) {
			// A permanent 404/410 means TMDB removed or merged the series;
			// mark it so the nightly refresh skips it and its followers see it.
			if ($e->isGone()) {
				$this->series->markSyncError($seriesId, 'Not found on TMDB (' . $e->statusCode() . ')');
			}
			throw $e;
		}

		$externalIds     = is_array($details['external_ids'] ?? null) ? $details['external_ids'] : [];
		$originalLanguage = TmdbValue::languageCode($details['original_language'] ?? null);

		// upsertSeries stamps synced_at right away; that early stamp keeps a
		// series being added safe from SeriesRepository::deleteOrphans. If the
		// rest of the sync fails, the old stamp is restored so the series stays
		// due instead of looking fresh for up to 50 days.
		$previousSync = $this->series->syncedAt($seriesId);
		$this->series->upsertSeries([
			'id'                 => $seriesId,
			'original_name'      => TmdbValue::text($details['original_name'] ?? null),
			'original_language'  => $originalLanguage,
			'imdb_id'            => TmdbValue::imdbId($externalIds['imdb_id'] ?? null),
			'first_air_year'     => $this->year((string) ($details['first_air_date'] ?? '')),
			'poster_path'        => TmdbValue::imagePath($details['poster_path'] ?? null),
			'status'             => $this->optionalText($details['status'] ?? null, 64),
			'networks'           => $this->networksJson($details['networks'] ?? null),
			'number_of_seasons'  => (int) ($details['number_of_seasons'] ?? 0),
			'number_of_episodes' => (int) ($details['number_of_episodes'] ?? 0),
			'last_air_date'      => $this->episodeDate($details['last_episode_to_air'] ?? null),
			'next_air_date'      => $this->episodeDate($details['next_episode_to_air'] ?? null),
		]);
		$ownSync = $this->series->syncedAt($seriesId);

		try {
			return $this->syncContent($seriesId, $details, $forceSeasons);
		} catch (\Throwable $e) {
			// Only while the stamp is still ours: an overlapping sync (e.g. the
			// nightly job while a user adds the series) may have stamped it since.
			$this->series->restoreSyncedAt($seriesId, $previousSync, $ownSync);
			throw $e;
		}
	}

	/**
	 * Stores translations and (changed) seasons of a series whose details
	 * were just fetched. Part of sync().
	 *
	 * @param array<string, mixed> $details
	 * @return int Number of seasons reloaded from TMDB.
	 */
	private function syncContent(int $seriesId, array $details, bool $forceSeasons): int {
		// Translations for all active languages: the base language comes from
		// the top-level payload fields (the request runs in that language),
		// all others from the translations payload of the same request.
		foreach ($this->languages as $lang) {
			if ($lang === $this->baseLang) {
				$this->series->upsertTranslation(
					$seriesId,
					$this->baseLang,
					TmdbValue::text($details['name'] ?? null),
					TmdbValue::longText($details['overview'] ?? null)
				);
				continue;
			}
			$translation = $this->translationFromPayload($details, $lang);
			// Empty name = "not available at TMDB" marker: keeps the series
			// from counting as incomplete in the due-check forever.
			$this->series->upsertTranslation($seriesId, $lang, $translation['name'], $translation['overview']);
		}

		$seasons = $this->storableSeasons($seriesId, $details['seasons'] ?? null);
		$maxSeason = 0;
		foreach ($seasons as $season) {
			$maxSeason = max($maxSeason, (int) ($season['season_number'] ?? 0));
		}

		// One pass over the stored episodes: per-season id sets, used both for
		// the change check and for language marker rows.
		$episodesBySeason = [];
		$parkedSeasons    = [];
		foreach ($this->series->episodesForSeries($seriesId) as $episode) {
			$episodesBySeason[(int) $episode['season_number']][] = (int) $episode['id'];
			if ((int) $episode['episode_number'] >= SeriesRepository::PARKED_EPISODE_MIN) {
				$parkedSeasons[(int) $episode['season_number']] = true;
			}
		}

		// Per-season title coverage in ONE query: which languages still lack
		// episode rows? Marker rows count as covered (anti-loop).
		$missingBySeason = $this->series->episodeLanguageCoverage($seriesId, $this->episodeLanguages);
		$extraLanguages  = array_values(array_diff($this->episodeLanguages, [$this->baseLang]));

		$refreshed = 0;
		// Seasons whose episode list was fetched and the ids it contained: the
		// basis for removing episodes TMDB no longer lists. An empty payload
		// only counts when the series details also report 0 episodes for the
		// season (e.g. an announced episode withdrawn) - otherwise it could be
		// a glitch and must not wipe the stored episodes.
		$fetchedSeasons = [];
		$fetchedIds     = [];
		foreach ($seasons as $season) {
			$seasonNumber = (int) ($season['season_number'] ?? 0);
			if ($seasonNumber < 1) {
				// Skip season 0 (specials).
				continue;
			}

			$expected = (int) ($season['episode_count'] ?? 0);
			$knownIds = $episodesBySeason[$seasonNumber] ?? [];
			$existing = count($knownIds);
			$missing  = $missingBySeason[$seasonNumber] ?? [];

			// Base fetch when episodes are new/changed, for the latest
			// (running) season, when base language title rows are missing, when
			// rows are still parked from an aborted sync (they would otherwise
			// count as present and keep the season from being fetched), or
			// when a backfill forces every season.
			$needsBase = $forceSeasons || $existing === 0 || $existing !== $expected
				|| $seasonNumber === $maxSeason || in_array($this->baseLang, $missing, true)
				|| isset($parkedSeasons[$seasonNumber]);

			if (!$needsBase && $missing === []) {
				continue;
			}

			$hasNew = false;
			if ($needsBase) {
				$seasonIds = $this->syncSeason($seriesId, $seasonNumber);
				if ($seasonIds !== [] || (isset($season['episode_count']) && $expected === 0)) {
					$fetchedSeasons[] = $seasonNumber;
					$fetchedIds       = [...$fetchedIds, ...$seasonIds];
				}
				$hasNew    = array_diff($seasonIds, $knownIds) !== [];
				$knownIds  = array_values(array_unique([...$knownIds, ...$seasonIds]));
				$refreshed++;
			}

			// Additional languages: all of them once new episodes appeared
			// (coverage cannot know them yet) or on a forced backfill (to fill
			// overviews on rows whose title coverage is already complete),
			// otherwise only the reported gaps.
			$fetchLangs = ($forceSeasons || $hasNew) ? $extraLanguages : array_values(array_diff($missing, [$this->baseLang]));
			foreach ($fetchLangs as $lang) {
				$this->syncSeasonTranslations($seriesId, $seasonNumber, $lang, $knownIds);
			}
		}

		// Only after all seasons: an episode moved to a later season is still
		// in its old one while that is processed and must not be deleted there.
		$this->series->deleteStaleEpisodes($seriesId, $fetchedSeasons, $fetchedIds);

		// Anti-loop: seasons that exist only in the database (no longer listed
		// by TMDB) can never be fetched again - mark their gaps as unavailable.
		$listedSeasons = array_map(static fn(array $s): int => (int) ($s['season_number'] ?? 0), $seasons);
		foreach ($missingBySeason as $seasonNumber => $langs) {
			if (in_array($seasonNumber, $listedSeasons, true)) {
				continue;
			}
			foreach ($episodesBySeason[$seasonNumber] ?? [] as $episodeId) {
				foreach ($langs as $lang) {
					$this->series->addEpisodeTranslationMarker($episodeId, $lang);
				}
			}
		}

		return $refreshed;
	}

	/**
	 * Fetches a single season (in the base request language) and stores its
	 * episodes plus their base language title rows ('' = marker, same
	 * convention as series). Renumbered episodes are moved to their new slots.
	 *
	 * @return list<int> Episode ids contained in the TMDB payload.
	 */
	private function syncSeason(int $seriesId, int $seasonNumber): array {
		$season = $this->tmdb->season($seriesId, $seasonNumber);
		$episodes = is_array($season['episodes'] ?? null) ? $season['episodes'] : [];

		$rows = [];
		foreach ($episodes as $episode) {
			if (!is_array($episode) || ($episode['id'] ?? null) === null) {
				continue;
			}
			// One episode the columns cannot hold must not abort the whole
			// series sync (every night again): skip it and name it in the log.
			$episodeId     = self::uintOrNull($episode['id'], self::MAX_EPISODE_ID);
			$episodeSeason = self::uintOrNull($episode['season_number'] ?? $seasonNumber, self::MAX_SEASON_NUMBER);
			$episodeNumber = self::uintOrNull($episode['episode_number'] ?? 0, SeriesRepository::PARKED_EPISODE_MIN - 1);
			if ($episodeId === null || $episodeId === 0 || $episodeSeason === null || $episodeNumber === null) {
				self::log(sprintf(
					'series %d season %d: skipped an episode that cannot be stored (id %s, season %s, number %s)',
					$seriesId,
					$seasonNumber,
					self::describe($episode['id']),
					self::describe($episode['season_number'] ?? null),
					self::describe($episode['episode_number'] ?? null)
				));
				continue;
			}
			$rows[$episodeId] = [
				'id'             => $episodeId,
				'season_number'  => $episodeSeason,
				'episode_number' => $episodeNumber,
				'air_date'       => $this->dateOrNull($episode['air_date'] ?? null),
				'runtime'        => $this->intOrNull($episode['runtime'] ?? null),
				'name'           => TmdbValue::text($episode['name'] ?? null),
				'overview'       => TmdbValue::longText($episode['overview'] ?? null),
			];
		}
		// For an id already stored under another series, the upsert would keep
		// that series_id but overwrite season, number, air date, runtime and
		// title in the other series' row. Leave that row untouched.
		foreach ($this->series->episodeIdsOfOtherSeries($seriesId, array_keys($rows)) as $foreignId) {
			self::log(sprintf('series %d season %d: skipped episode %d, it belongs to another series', $seriesId, $seasonNumber, $foreignId));
			unset($rows[$foreignId]);
		}
		$rows = array_values($rows);
		// An empty payload leaves the stored episodes untouched: parking them
		// would hide them as unplaceable rows if the payload was a glitch. A
		// season that is really empty is cleared by deleteStaleEpisodes().
		if ($rows !== []) {
			$this->series->replaceSeasonEpisodes($seriesId, $seasonNumber, $rows, $this->baseLang);
		}

		return array_column($rows, 'id');
	}

	/**
	 * Fetches a season once more in the given language and stores the episode
	 * titles. Episodes missing from the payload get an empty marker row so the
	 * series does not stay due forever (anti-loop) - but only where no row
	 * exists yet: an incomplete payload must not wipe stored titles.
	 *
	 * @param list<int> $episodeIds Stored episode ids of this season.
	 */
	private function syncSeasonTranslations(int $seriesId, int $seasonNumber, string $lang, array $episodeIds): void {
		$season = $this->tmdb->season($seriesId, $seasonNumber, $lang);
		$episodes = is_array($season['episodes'] ?? null) ? $season['episodes'] : [];

		$names     = [];
		$overviews = [];
		foreach ($episodes as $episode) {
			$episodeId = (int) ($episode['id'] ?? 0);
			if ($episodeId !== 0) {
				$names[$episodeId]     = TmdbValue::text($episode['name'] ?? null);
				$overviews[$episodeId] = TmdbValue::longText($episode['overview'] ?? null);
			}
		}

		foreach ($episodeIds as $episodeId) {
			if (array_key_exists($episodeId, $names)) {
				$this->series->upsertEpisodeTranslation($episodeId, $lang, $names[$episodeId], $overviews[$episodeId]);
			} else {
				$this->series->addEpisodeTranslationMarker($episodeId, $lang);
			}
		}
	}

	/**
	 * The season entries of a details payload that can be processed: arrays
	 * with a storable season number. Others are skipped and logged.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function storableSeasons(int $seriesId, mixed $seasons): array {
		if (!is_array($seasons)) {
			return [];
		}

		$result = [];
		foreach ($seasons as $season) {
			if (is_array($season) && self::uintOrNull($season['season_number'] ?? 0, self::MAX_SEASON_NUMBER) !== null) {
				/** @var array<string, mixed> $season */
				$result[] = $season;
				continue;
			}
			self::log(sprintf(
				'series %d: skipped a season entry that cannot be stored (season %s)',
				$seriesId,
				self::describe(is_array($season) ? ($season['season_number'] ?? null) : $season)
			));
		}

		return $result;
	}

	/**
	 * A non-negative integer up to $max (also from a digit string), else null.
	 */
	private static function uintOrNull(mixed $value, int $max): ?int {
		if (is_string($value) && preg_match('/\A\d{1,10}\z/', $value) === 1) {
			$value = (int) $value;
		}

		return is_int($value) && $value >= 0 && $value <= $max ? $value : null;
	}

	/**
	 * Short, log-safe rendering of a payload value.
	 */
	private static function describe(mixed $value): string {
		$text = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

		return mb_strimwidth((string) preg_replace('/[^\x20-\x7E]/', '?', $text), 0, 40, '...');
	}

	/**
	 * Writes a note about skipped TMDB data to the error log (cron log or
	 * web server log).
	 */
	private static function log(string $message): void {
		error_log('Catenvis: ' . $message);
	}

	/**
	 * Extracts the year from a TMDB date (YYYY-MM-DD).
	 */
	private function year(string $date): ?int {
		return preg_match('/^(\d{4})/', $date, $m) === 1 ? (int) $m[1] : null;
	}

	/**
	 * Returns the air_date field of a TMDB episode object as a date or null.
	 *
	 * @param mixed $episode
	 */
	private function episodeDate(mixed $episode): ?string {
		if (!is_array($episode)) {
			return null;
		}

		return $this->dateOrNull($episode['air_date'] ?? null);
	}

	private function dateOrNull(mixed $value): ?string {
		if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value;
		}

		return null;
	}

	/**
	 * Text cut to $maxChars characters (see TmdbValue::text), null when empty.
	 */
	private function optionalText(mixed $value, int $maxChars): ?string {
		$text = TmdbValue::text($value, $maxChars);

		return $text !== '' ? $text : null;
	}

	/**
	 * A positive integer (TMDB uses 0/null for an unknown episode runtime),
	 * otherwise null so it does not distort the runtime averages.
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_int($value) && $value > 0) {
			return $value;
		}
		if (is_string($value) && ctype_digit($value) && $value !== '0') {
			return (int) $value;
		}

		return null;
	}

	/**
	 * Builds a compact JSON [{name, logo_path}] from the TMDB networks, or null.
	 *
	 * @param mixed $networks
	 */
	private function networksJson(mixed $networks): ?string {
		if (!is_array($networks) || $networks === []) {
			return null;
		}

		$list = [];
		foreach ($networks as $network) {
			if (!is_array($network)) {
				continue;
			}
			$name = $this->optionalText($network['name'] ?? null, self::NETWORK_NAME_LENGTH);
			if ($name === null) {
				continue;
			}
			$list[] = ['name' => $name, 'logo_path' => TmdbValue::imagePath($network['logo_path'] ?? null)];
			// Bounds the TEXT column (65,535 bytes): 20 names of 100 characters
			// stay far below it even when every character is escaped. Real
			// series list a handful at most.
			if (count($list) === 20) {
				break;
			}
		}

		return $list === [] ? null : (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Determines the title + description of a language from the translations
	 * appendix of the TMDB details. Per language, multiple region entries may
	 * exist (e.g. pt-BR/pt-PT) - per field the first non-empty value wins.
	 * If the title is missing and it is the original language of the series, the
	 * original title applies; otherwise the name stays empty (marker row).
	 *
	 * @param array<string, mixed> $details
	 * @return array{name: string, overview: string|null}
	 */
	private function translationFromPayload(array $details, string $lang): array {
		$name     = null;
		$overview = null;

		$translations = $details['translations']['translations'] ?? null;
		if (is_array($translations)) {
			foreach ($translations as $translation) {
				if (($translation['iso_639_1'] ?? null) !== $lang) {
					continue;
				}
				$name     ??= $this->optionalText($translation['data']['name'] ?? null, TmdbValue::NAME_LENGTH);
				$overview ??= TmdbValue::longText($translation['data']['overview'] ?? null);
				if ($name !== null && $overview !== null) {
					break;
				}
			}
		}

		if ($name === null && ($details['original_language'] ?? null) === $lang) {
			$name = $this->optionalText($details['original_name'] ?? null, TmdbValue::NAME_LENGTH);
		}

		return ['name' => $name ?? '', 'overview' => $overview];
	}
}
