<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * Selects the episode title to display, following the fallback chain
 * own language -> English -> base language. Empty strings mark a missing
 * translation (same convention as episode_translations.name = '').
 *
 * TMDB auto-fills untitled episodes with the localized word for "Episode"
 * plus the number ("Folge 5", "Episode 5", "Épisode 5"). These are non-empty
 * yet carry no real title, so they are detected and skipped at read time -
 * which also repairs already-stored placeholder rows without re-fetching.
 *
 * Detection is per language: a candidate is only tested against the
 * placeholder word of ITS OWN language. So a German title "Episode 1" (a real
 * title) is kept, because the German placeholder word is "Folge", not
 * "Episode".
 */
final class EpisodeTitle {
	/**
	 * Localized TMDB placeholder word for "Episode" per language (installed UI
	 * languages plus pt). A language absent here cannot be checked, so its
	 * titles are always treated as real (graceful degradation, no regression).
	 *
	 * @var array<string, string>
	 */
	private const PLACEHOLDER_WORDS = [
		'de' => 'Folge',
		'en' => 'Episode',
		'es' => 'Episodio',
		'it' => 'Episodio',
		'fr' => 'Épisode',
		'pt' => 'Episódio',
	];

	/**
	 * First tier that holds a real title (non-empty and not a placeholder in
	 * its own language), otherwise '' (the template then shows the episode
	 * code only).
	 *
	 * @param list<array{0: string, 1: string}> $tiers [name, language] pairs
	 *        in priority order, e.g. [[own, 'de'], [english, 'en'], [base, 'de']].
	 */
	public static function pick(array $tiers): string {
		foreach ($tiers as [$name, $lang]) {
			if ($name !== '' && !self::isPlaceholder($name, $lang)) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Whether $name is a TMDB auto-placeholder for $lang: that language's
	 * "Episode" word followed by a bare number. Checking only the language's
	 * own word keeps a German "Episode 1" real, and a "Level 3" title safe
	 * (its word is not a placeholder word). The number is deliberately NOT
	 * anchored to the episode's position: TMDB numbers placeholders absolutely
	 * across seasons ("Episode 9" for the first episode of season 2), so the
	 * position would not match from season 2 onwards and the placeholder would
	 * leak through as a fake title.
	 */
	public static function isPlaceholder(string $name, string $lang): bool {
		$word = self::PLACEHOLDER_WORDS[$lang] ?? null;
		if ($word === null) {
			return false;
		}

		return preg_match('/^' . preg_quote($word, '/') . '\s+\d+$/iu', $name) === 1;
	}
}
