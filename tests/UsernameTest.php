<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Username;
use PHPUnit\Framework\TestCase;

/**
 * Covers the storable-username rule: at most 64 characters (not bytes) and
 * valid UTF-8, so collation-ignored padding cannot slip past the column width.
 */
final class UsernameTest extends TestCase {
	public function testAcceptsNamesUpToTheColumnWidth(): void {
		self::assertTrue(Username::fits(''));
		self::assertTrue(Username::fits('admin'));
		self::assertTrue(Username::fits(str_repeat('a', 64)));
	}

	public function testCountsCharactersNotBytes(): void {
		self::assertTrue(Username::fits(str_repeat('ü', 64)));
		self::assertTrue(Username::fits(str_repeat('😀', 64)));
		self::assertFalse(Username::fits(str_repeat('ü', 65)));
	}

	public function testRejectsOverlongNames(): void {
		self::assertFalse(Username::fits(str_repeat('a', 65)));
	}

	public function testRejectsCollationIgnoredPadding(): void {
		self::assertFalse(Username::fits('admin' . str_repeat("\u{0001}", 60)));
		self::assertFalse(Username::fits(str_repeat("\u{00A0}", 65)));
	}

	public function testRejectsNewlinesBeyondTheLimit(): void {
		self::assertFalse(Username::fits(str_repeat("\n", 65)));
	}

	public function testRejectsInvalidUtf8(): void {
		self::assertFalse(Username::fits("admin\xFF"));
	}
}
