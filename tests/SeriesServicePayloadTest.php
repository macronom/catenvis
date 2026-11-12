<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\SeriesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the payload checks of the series sync that need no database: the
 * network list fits its TEXT column, and only storable numbers are accepted.
 */
final class SeriesServicePayloadTest extends TestCase {
	public function testNetworkListFitsTheColumnEvenWithEscapedCharacters(): void {
		$networks = [];
		for ($i = 0; $i < 25; $i++) {
			$networks[] = ['name' => str_repeat("\u{2028}\"\\\x01", 64), 'logo_path' => '/logo.png'];
		}

		$json = $this->networksJson($networks);

		self::assertIsString($json);
		self::assertLessThanOrEqual(65535, strlen($json));
		$decoded = json_decode($json, true);
		self::assertIsArray($decoded);
		self::assertCount(20, $decoded);
		self::assertSame(100, mb_strlen($decoded[0]['name']));
	}

	public function testNetworkListKeepsUnicodeUnescaped(): void {
		$json = $this->networksJson([['name' => 'Télé Québec', 'logo_path' => '/a.png'], 'not an array']);

		self::assertSame('[{"name":"Télé Québec","logo_path":"/a.png"}]', $json);
	}

	public function testAcceptsOnlyStorableUnsignedNumbers(): void {
		$check = new ReflectionMethod(SeriesService::class, 'uintOrNull');

		self::assertSame(0, $check->invoke(null, 0, 65535));
		self::assertSame(65535, $check->invoke(null, 65535, 65535));
		self::assertSame(12, $check->invoke(null, '12', 65535));
		self::assertNull($check->invoke(null, 65536, 65535));
		self::assertNull($check->invoke(null, -1, 65535));
		self::assertNull($check->invoke(null, 4294967296, 4294967295));
		self::assertNull($check->invoke(null, 1.5, 65535));
		self::assertNull($check->invoke(null, '1e3', 65535));
		self::assertNull($check->invoke(null, null, 65535));
	}

	/**
	 * Runs the private network list builder.
	 *
	 * @param array<mixed> $networks
	 */
	private function networksJson(array $networks): ?string {
		$class = new ReflectionClass(SeriesService::class);
		/** @var string|null $json */
		$json = $class->getMethod('networksJson')->invoke($class->newInstanceWithoutConstructor(), $networks);

		return $json;
	}
}
