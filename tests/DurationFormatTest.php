<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\DurationFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers splitting a minute count into days/hours/minutes, including the
 * boundaries and the negative guard.
 */
final class DurationFormatTest extends TestCase {
	/**
	 * @param array{days: int, hours: int, minutes: int} $expected
	 */
	#[DataProvider('cases')]
	public function testParts(int $minutes, array $expected): void {
		self::assertSame($expected, DurationFormat::parts($minutes));
	}

	/**
	 * @return iterable<string, array{int, array{days: int, hours: int, minutes: int}}>
	 */
	public static function cases(): iterable {
		yield 'zero'            => [0, ['days' => 0, 'hours' => 0, 'minutes' => 0]];
		yield 'under an hour'   => [59, ['days' => 0, 'hours' => 0, 'minutes' => 59]];
		yield 'exactly an hour' => [60, ['days' => 0, 'hours' => 1, 'minutes' => 0]];
		yield 'hour and half'   => [90, ['days' => 0, 'hours' => 1, 'minutes' => 30]];
		yield 'exactly a day'   => [1440, ['days' => 1, 'hours' => 0, 'minutes' => 0]];
		yield 'day and an hour' => [1500, ['days' => 1, 'hours' => 1, 'minutes' => 0]];
		yield 'mixed'           => [3845, ['days' => 2, 'hours' => 16, 'minutes' => 5]];
		yield 'negative guard'  => [-10, ['days' => 0, 'hours' => 0, 'minutes' => 0]];
	}
}
