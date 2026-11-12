<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * Checks and trims values from TMDB payloads before they are stored, so
 * every value fits its column and paths/ids have their expected shape.
 * Invalid values become empty (null or ''), never an error: a bad field
 * must not abort the sync of a whole series.
 */
final class TmdbValue {
	/** Width of the name/title columns (VARCHAR(255)) in characters. */
	public const NAME_LENGTH = 255;
	/** Size of the TEXT columns in bytes. */
	public const TEXT_BYTES = 65535;

	/**
	 * Image path as TMDB returns it ("/abc123.jpg"), or null. Only a single
	 * path segment of harmless characters: it is appended to an image URL.
	 */
	public static function imagePath(mixed $value): ?string {
		return is_string($value) && preg_match('#\A/[A-Za-z0-9._-]{1,200}\z#', $value) === 1 ? $value : null;
	}

	/**
	 * IMDb id ("tt" plus digits, fits VARCHAR(20)), or null.
	 */
	public static function imdbId(mixed $value): ?string {
		return is_string($value) && preg_match('/\Att\d{1,12}\z/', $value) === 1 ? $value : null;
	}

	/**
	 * ISO 639 language code (two or three lowercase letters), or null.
	 */
	public static function languageCode(mixed $value): ?string {
		return is_string($value) && preg_match('/\A[a-z]{2,3}\z/', $value) === 1 ? $value : null;
	}

	/**
	 * Text cut to at most $maxChars characters; '' for non-strings and
	 * invalid UTF-8.
	 */
	public static function text(mixed $value, int $maxChars = self::NAME_LENGTH): string {
		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
			return '';
		}

		return mb_substr($value, 0, $maxChars, 'UTF-8');
	}

	/**
	 * Long text for a TEXT column, cut to its byte size without splitting a
	 * character; null when empty, not a string or invalid UTF-8.
	 */
	public static function longText(mixed $value): ?string {
		if (!is_string($value) || $value === '' || !mb_check_encoding($value, 'UTF-8')) {
			return null;
		}

		return mb_strcut($value, 0, self::TEXT_BYTES, 'UTF-8');
	}
}
