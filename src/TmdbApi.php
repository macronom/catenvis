<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * The TMDB calls the series sync depends on. Implemented by TmdbClient; a
 * separate type so the sync can run against a stand-in (tests).
 */
interface TmdbApi {
	/**
	 * ISO-639-1 code of the base request language (e.g. 'de').
	 */
	public function baseLanguageCode(): string;

	/**
	 * Series details including translations and external ids.
	 *
	 * @return array<string, mixed>
	 * @throws TmdbException
	 */
	public function tvDetails(int $seriesId): array;

	/**
	 * One season with its episodes.
	 *
	 * @param string|null $language Language of the returned fields (default: base language).
	 * @return array<string, mixed>
	 * @throws TmdbException
	 */
	public function season(int $seriesId, int $seasonNumber, ?string $language = null): array;
}
