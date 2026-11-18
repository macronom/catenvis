<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * Turns the free-text input of the library search into search terms and
 * SQL LIKE patterns.
 *
 * Every whitespace-separated word is a term; a series matches when each term
 * occurs somewhere in one of its titles (order does not matter, no wildcards
 * needed). A '*' is accepted out of habit and treated like a space. Case and
 * accents are handled by the database collation.
 */
final class SearchQuery {
	/** Longest input that is considered (in characters). */
	public const MAX_LENGTH = 100;

	/** Most terms that are considered (each one adds bound parameters). */
	public const MAX_TERMS = 8;

	/** Escape character for the LIKE patterns (see likeEscape()). */
	public const ESCAPE_CHAR = '!';

	/**
	 * The distinct search terms of an input, in input order.
	 *
	 * @return list<string>
	 */
	public static function terms(string $input): array {
		$input = mb_substr(str_replace('*', ' ', $input), 0, self::MAX_LENGTH);
		$words = preg_split('/\s+/u', $input, -1, PREG_SPLIT_NO_EMPTY);
		if ($words === false) {
			return [];
		}

		$terms = [];
		foreach ($words as $word) {
			if (!in_array($word, $terms, true)) {
				$terms[] = $word;
			}
		}

		return array_slice($terms, 0, self::MAX_TERMS);
	}

	/**
	 * LIKE pattern matching the term anywhere in a value.
	 */
	public static function containsPattern(string $term): string {
		return '%' . self::likeEscape($term) . '%';
	}

	/**
	 * LIKE pattern matching values that start with the given terms
	 * (joined by single spaces).
	 *
	 * @param list<string> $terms
	 */
	public static function prefixPattern(array $terms): string {
		return self::likeEscape(implode(' ', $terms)) . '%';
	}

	/**
	 * Escapes the LIKE wildcards so they match literally; the patterns must
	 * be used with "ESCAPE '!'" (a backslash would depend on the SQL mode).
	 */
	public static function likeEscape(string $value): string {
		$c = self::ESCAPE_CHAR;

		return str_replace([$c, '%', '_'], [$c . $c, $c . '%', $c . '_'], $value);
	}
}
