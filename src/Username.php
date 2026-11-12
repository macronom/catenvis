<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * Rules for usernames as they can be stored in users.username and
 * login_attempts.username (both VARCHAR(64), utf8mb4).
 */
final class Username {
	/** Column width in characters. */
	public const MAX_LENGTH = 64;

	/**
	 * Whether the name is valid UTF-8 and fits the column. Uses preg_match
	 * instead of mb_strlen so it does not depend on ext-mbstring.
	 */
	public static function fits(string $username): bool {
		return preg_match('/\A.{0,' . self::MAX_LENGTH . '}\z/us', $username) === 1;
	}
}
