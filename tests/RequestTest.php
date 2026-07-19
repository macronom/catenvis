<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers request parsing: HTTP method, path normalization and the
 * type-safe accessors (body wins over query, trimming, numeric filters).
 */
final class RequestTest extends TestCase {
	public function testMethodIsUppercasedAndPostDetected(): void {
		$request = new Request([], [], ['REQUEST_METHOD' => 'post']);

		self::assertSame('POST', $request->method());
		self::assertTrue($request->isPost());
	}

	public function testMethodDefaultsToGetWhenAbsent(): void {
		$request = new Request([], [], []);

		self::assertSame('GET', $request->method());
		self::assertFalse($request->isPost());
	}

	/**
	 * @param array<string, mixed> $server
	 */
	#[DataProvider('pathCases')]
	public function testPathIsNormalized(array $server, string $basePath, string $expected): void {
		$request = new Request([], [], $server, $basePath);

		self::assertSame($expected, $request->path());
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, string, string}>
	 */
	public static function pathCases(): iterable {
		yield 'strips base path and query string' => [['REQUEST_URI' => '/catenvis/series/5?foo=bar'], '/catenvis', '/series/5'];
		yield 'root after stripping base path' => [['REQUEST_URI' => '/catenvis/'], '/catenvis', '/'];
		yield 'plain root' => [['REQUEST_URI' => '/'], '', '/'];
		yield 'removes trailing slash' => [['REQUEST_URI' => '/series/5/'], '', '/series/5'];
		yield 'decodes percent-encoding' => [['REQUEST_URI' => '/foo%20bar'], '', '/foo bar'];
		yield 'leaves path unchanged when base path does not match' => [['REQUEST_URI' => '/series'], '/other', '/series'];
		yield 'defaults to root when REQUEST_URI absent' => [[], '', '/'];
	}

	public function testGetStringPrefersBodyAndTrims(): void {
		$request = new Request(['x' => 'from-query'], ['x' => '  from-body  '], []);

		self::assertSame('from-body', $request->getString('x'));
	}

	public function testGetStringFallsBackToQueryThenDefault(): void {
		$request = new Request(['x' => 'from-query'], [], []);

		self::assertSame('from-query', $request->getString('x'));
		self::assertSame('fallback', $request->getString('missing', 'fallback'));
	}

	public function testGetStringReturnsDefaultForNonScalar(): void {
		$request = new Request([], ['x' => ['a', 'b']], []);

		self::assertSame('', $request->getString('x'));
	}

	#[DataProvider('intCases')]
	public function testGetInt(mixed $raw, int $expected): void {
		$request = new Request([], ['x' => $raw], []);

		self::assertSame($expected, $request->getInt('x', -1));
	}

	/**
	 * @return iterable<string, array{mixed, int}>
	 */
	public static function intCases(): iterable {
		yield 'numeric string' => ['42', 42];
		yield 'float string is truncated' => ['3.9', 3];
		yield 'non-numeric returns default' => ['abc', -1];
		yield 'array returns default' => [['1'], -1];
	}

	public function testGetIntFallsBackToDefaultWhenAbsent(): void {
		$request = new Request([], [], []);

		self::assertSame(7, $request->getInt('missing', 7));
	}

	#[DataProvider('boolCases')]
	public function testGetBool(mixed $raw, bool $expected): void {
		$request = new Request([], ['x' => $raw], []);

		self::assertSame($expected, $request->getBool('x'));
	}

	/**
	 * @return iterable<string, array{mixed, bool}>
	 */
	public static function boolCases(): iterable {
		yield 'string one' => ['1', true];
		yield 'int one' => [1, true];
		yield 'true boolean' => [true, true];
		yield 'string true' => ['true', true];
		yield 'string on' => ['on', true];
		yield 'string zero' => ['0', false];
		yield 'string false' => ['false', false];
		yield 'arbitrary value' => ['yes', false];
	}

	public function testGetBoolIsFalseWhenAbsent(): void {
		$request = new Request([], [], []);

		self::assertFalse($request->getBool('missing'));
	}

	public function testGetIntListFiltersToIntegers(): void {
		$request = new Request([], ['ids' => ['1', '2', 'x', 3, '4.5']], []);

		self::assertSame([1, 2, 3, 4], $request->getIntList('ids'));
	}

	public function testGetIntListReturnsEmptyForNonArray(): void {
		$request = new Request([], ['ids' => '1'], []);

		self::assertSame([], $request->getIntList('ids'));
	}

	public function testGetIntListPrefersBodyOverQuery(): void {
		$request = new Request(['ids' => ['9']], ['ids' => ['1', '2']], []);

		self::assertSame([1, 2], $request->getIntList('ids'));
	}
}
