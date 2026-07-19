<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

/**
 * Splits a runtime given in minutes into whole days, hours and minutes for
 * display. The localized rendering (unit words) happens in the view via the
 * shared $duration() helper; this class stays pure and unit-testable.
 */
final class DurationFormat {
	/**
	 * @return array{days: int, hours: int, minutes: int}
	 */
	public static function parts(int $minutes): array {
		$minutes = max(0, $minutes);

		return [
			'days'    => intdiv($minutes, 1440),
			'hours'   => intdiv($minutes % 1440, 60),
			'minutes' => $minutes % 60,
		];
	}
}
