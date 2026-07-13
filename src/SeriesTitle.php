<?php

declare(strict_types=1);

namespace Catenvis;

/**
 * Selects the series title to display depending on the language preference.
 * Works for DB rows (with name/title_en) as well as for TMDB search matches.
 * name may be empty/NULL (missing translation or marker row) -
 * then the chain English -> original title applies.
 */
final class SeriesTitle {
	/**
	 * @param array<string, mixed> $row      Serie mit name, title_en, original_name, original_language.
	 * @param string               $lang     ISO code of the own language or 'original'.
	 * @param string               $userLang Content language of the user (for the original mode).
	 */
	public static function pick(array $row, string $lang, string $userLang = 'en'): string {
		$name = (string) ($row['name'] ?? '');

		// Own language: translated title, otherwise English, otherwise original.
		if ($lang !== 'original') {
			return self::first([$name, $row['title_en'] ?? null, $row['original_name'] ?? null]);
		}

		// Productions in the own language keep their translated title.
		if (($row['original_language'] ?? null) === $userLang) {
			return self::first([$name, $row['original_name'] ?? null]);
		}

		return self::first([$row['title_en'] ?? null, $row['original_name'] ?? null, $name]);
	}

	/**
	 * First non-empty candidate (or '').
	 *
	 * @param list<mixed> $candidates
	 */
	private static function first(array $candidates): string {
		foreach ($candidates as $candidate) {
			if (is_string($candidate) && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}
}
