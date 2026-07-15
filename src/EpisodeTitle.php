<?php

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
	public static function pick(array $tiers, int $episodeNumber): string {
		foreach ($tiers as [$name, $lang]) {
			if ($name !== '' && !self::isPlaceholder($name, $episodeNumber, $lang)) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Whether $name is a TMDB auto-placeholder for $lang: that language's
	 * "Episode" word followed by exactly this episode's number. Anchoring to
	 * the episode number keeps genuine "Word N" titles ("Level 3") safe;
	 * checking only the language's own word keeps a German "Episode 1" real.
	 */
	public static function isPlaceholder(string $name, int $episodeNumber, string $lang): bool {
		$word = self::PLACEHOLDER_WORDS[$lang] ?? null;
		if ($word === null) {
			return false;
		}

		return preg_match('/^' . preg_quote($word, '/') . '\s+0*(\d+)$/iu', $name, $m) === 1
			&& (int) $m[1] === $episodeNumber;
	}
}
