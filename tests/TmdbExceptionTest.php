<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\TmdbException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the classification of TMDB failures into permanent "gone" (404/410)
 * versus transient errors that should be retried.
 */
final class TmdbExceptionTest extends TestCase {
	public function testStatusCodeIsCarried(): void {
		self::assertSame(404, (new TmdbException('x', 404))->statusCode());
		self::assertSame(0, (new TmdbException('x'))->statusCode());
	}

	#[DataProvider('goneCases')]
	public function testIsGone(int $status, bool $expected): void {
		self::assertSame($expected, (new TmdbException('x', $status))->isGone());
	}

	/**
	 * @return iterable<string, array{int, bool}>
	 */
	public static function goneCases(): iterable {
		yield '404 Not Found is gone' => [404, true];
		yield '410 Gone is gone' => [410, true];
		yield '0 transport error is transient' => [0, false];
		yield '429 rate limit is transient' => [429, false];
		yield '500 server error is transient' => [500, false];
		yield '503 unavailable is transient' => [503, false];
		yield '401 unauthorized is not gone' => [401, false];
	}
}
