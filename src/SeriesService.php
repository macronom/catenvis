<?php

declare(strict_types=1);

namespace Catenvis;

use Catenvis\Repository\SeriesRepository;

/**
 * Fetches series/episode data from the TMDB API and stores it in the database.
 * Used both when adding a series and by the daily cron.
 */
final class SeriesService {
	private TmdbClient $tmdb;
	private SeriesRepository $series;
	/** @var list<string> Active series content languages (always contains the base language and 'en'). */
	private array $languages;
	/** @var list<string> Active episode title languages (base language plus non-admin user prefs). */
	private array $episodeLanguages;
	/** ISO code of the TMDB base request language (top-level payload fields). */
	private string $baseLang;

	/**
	 * @param list<string> $languages        Active series content languages.
	 * @param list<string> $episodeLanguages Active episode title languages.
	 */
	public function __construct(TmdbClient $tmdb, SeriesRepository $series, array $languages = ['en'], array $episodeLanguages = ['en']) {
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
		$originalLanguage = $this->stringOrNull($details['original_language'] ?? null);

		$this->series->upsertSeries([
			'id'                 => $seriesId,
			'original_name'      => (string) ($details['original_name'] ?? ''),
			'original_language'  => $originalLanguage,
			'imdb_id'            => $this->stringOrNull($externalIds['imdb_id'] ?? null),
			'first_air_year'     => $this->year((string) ($details['first_air_date'] ?? '')),
			'poster_path'        => $this->stringOrNull($details['poster_path'] ?? null),
			'status'             => $this->stringOrNull($details['status'] ?? null),
			'networks'           => $this->networksJson($details['networks'] ?? null),
			'number_of_seasons'  => (int) ($details['number_of_seasons'] ?? 0),
			'number_of_episodes' => (int) ($details['number_of_episodes'] ?? 0),
			'last_air_date'      => $this->episodeDate($details['last_episode_to_air'] ?? null),
			'next_air_date'      => $this->episodeDate($details['next_episode_to_air'] ?? null),
		]);

		// Translations for all active languages: the base language comes from
		// the top-level payload fields (the request runs in that language),
		// all others from the translations payload of the same request.
		foreach ($this->languages as $lang) {
			if ($lang === $this->baseLang) {
				$this->series->upsertTranslation(
					$seriesId,
					$this->baseLang,
					(string) ($details['name'] ?? ''),
					$this->stringOrNull($details['overview'] ?? null)
				);
				continue;
			}
			$translation = $this->translationFromPayload($details, $lang);
			// Empty name = "not available at TMDB" marker: keeps the series
			// from counting as incomplete in the due-check forever.
			$this->series->upsertTranslation($seriesId, $lang, $translation['name'], $translation['overview']);
		}

		$seasons = is_array($details['seasons'] ?? null) ? $details['seasons'] : [];
		$maxSeason = 0;
		foreach ($seasons as $season) {
			$maxSeason = max($maxSeason, (int) ($season['season_number'] ?? 0));
		}

		// One pass over the stored episodes: per-season id sets, used both for
		// the change check and for language marker rows.
		$episodesBySeason = [];
		foreach ($this->series->episodesForSeries($seriesId) as $episode) {
			$episodesBySeason[(int) $episode['season_number']][] = (int) $episode['id'];
		}

		// Per-season title coverage in ONE query: which languages still lack
		// episode rows? Marker rows count as covered (anti-loop).
		$missingBySeason = $this->series->episodeLanguageCoverage($seriesId, $this->episodeLanguages);
		$extraLanguages  = array_values(array_diff($this->episodeLanguages, [$this->baseLang]));

		$refreshed = 0;
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
			// (running) season, when base language title rows are missing, or
			// when a backfill forces every season.
			$needsBase = $forceSeasons || $existing === 0 || $existing !== $expected
				|| $seasonNumber === $maxSeason || in_array($this->baseLang, $missing, true);

			if (!$needsBase && $missing === []) {
				continue;
			}

			$hasNew = false;
			if ($needsBase) {
				$seasonIds = $this->syncSeason($seriesId, $seasonNumber);
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

		// Anti-loop: seasons that exist only in the database (no longer listed
		// by TMDB) can never be fetched again - mark their gaps as unavailable.
		$listedSeasons = array_map(static fn(array $s): int => (int) ($s['season_number'] ?? 0), $seasons);
		foreach ($missingBySeason as $seasonNumber => $langs) {
			if (in_array($seasonNumber, $listedSeasons, true)) {
				continue;
			}
			foreach ($episodesBySeason[$seasonNumber] ?? [] as $episodeId) {
				foreach ($langs as $lang) {
					$this->series->upsertEpisodeTranslation($episodeId, $lang, '');
				}
			}
		}

		return $refreshed;
	}

	/**
	 * Fetches a single season (in the base request language) and stores its
	 * episodes plus their base language title rows ('' = marker, same
	 * convention as series).
	 *
	 * @return list<int> Episode ids contained in the TMDB payload.
	 */
	private function syncSeason(int $seriesId, int $seasonNumber): array {
		$season = $this->tmdb->season($seriesId, $seasonNumber);
		$episodes = is_array($season['episodes'] ?? null) ? $season['episodes'] : [];

		$ids = [];
		foreach ($episodes as $episode) {
			$episodeId = (int) ($episode['id'] ?? 0);
			if ($episodeId === 0) {
				continue;
			}
			$this->series->upsertEpisode([
				'id'             => $episodeId,
				'series_id'      => $seriesId,
				'season_number'  => (int) ($episode['season_number'] ?? $seasonNumber),
				'episode_number' => (int) ($episode['episode_number'] ?? 0),
				'air_date'       => $this->dateOrNull($episode['air_date'] ?? null),
			]);
			$this->series->upsertEpisodeTranslation(
				$episodeId,
				$this->baseLang,
				(string) ($episode['name'] ?? ''),
				$this->stringOrNull($episode['overview'] ?? null)
			);
			$ids[] = $episodeId;
		}

		return $ids;
	}

	/**
	 * Fetches a season once more in the given language and stores the episode
	 * titles. Episodes missing from the payload (or with empty names) get an
	 * empty marker row so the series does not stay due forever (anti-loop).
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
				$names[$episodeId]     = (string) ($episode['name'] ?? '');
				$overviews[$episodeId] = $this->stringOrNull($episode['overview'] ?? null);
			}
		}

		foreach ($episodeIds as $episodeId) {
			$this->series->upsertEpisodeTranslation($episodeId, $lang, $names[$episodeId] ?? '', $overviews[$episodeId] ?? null);
		}
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

	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) && $value !== '') {
			return $value;
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
			$name = $this->stringOrNull($network['name'] ?? null);
			if ($name === null) {
				continue;
			}
			$list[] = ['name' => $name, 'logo_path' => $this->stringOrNull($network['logo_path'] ?? null)];
		}

		return $list === [] ? null : (string) json_encode($list);
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
				$name     ??= $this->stringOrNull($translation['data']['name'] ?? null);
				$overview ??= $this->stringOrNull($translation['data']['overview'] ?? null);
				if ($name !== null && $overview !== null) {
					break;
				}
			}
		}

		if ($name === null && ($details['original_language'] ?? null) === $lang) {
			$name = $this->stringOrNull($details['original_name'] ?? null);
		}

		return ['name' => $name ?? '', 'overview' => $overview];
	}
}
