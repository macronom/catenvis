<?php

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\DataFreshness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers deriving the "data is stale" signal from the newest successful sync:
 * fresh vs. stale, the disabled/unknown cases, and clock-skew robustness.
 */
final class DataFreshnessTest extends TestCase {
	#[DataProvider('cases')]
	public function testStaleDays(?string $lastSync, string $now, int $threshold, ?int $expected): void {
		self::assertSame($expected, DataFreshness::staleDays($lastSync, $now, $threshold));
	}

	/**
	 * @return iterable<string, array{?string, string, int, ?int}>
	 */
	public static function cases(): iterable {
		$now = '2026-07-18 04:30:00';

		yield 'never synced yet is unknown'      => [null, $now, 3, null];
		yield 'synced today is fresh'            => ['2026-07-18 04:00:00', $now, 3, null];
		yield 'just under threshold is fresh'    => ['2026-07-15 05:00:00', $now, 3, null]; // 2d 23.5h -> 2
		yield 'exactly at threshold is stale'    => ['2026-07-15 04:00:00', $now, 3, 3];
		yield 'well past threshold reports days' => ['2026-07-13 04:30:00', $now, 3, 5];
		yield 'future timestamp is not stale'    => ['2026-07-20 00:00:00', $now, 3, null];
		yield 'threshold below 1 disables it'    => ['2020-01-01 00:00:00', $now, 0, null];
		yield 'unparseable timestamp is unknown' => ['not-a-date', $now, 3, null];
	}
}
