<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Auth;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Covers the password hashing rules: one fixed bcrypt cost for new hashes and
 * the dummy hash (equal login timing for unknown names), and the null-byte
 * check that keeps password_hash from throwing.
 */
final class AuthPasswordTest extends TestCase {
	public function testHashesWithBcryptCost12(): void {
		$hash = Auth::hashPassword('correct horse');

		self::assertStringStartsWith('$2y$12$', $hash);
		self::assertTrue(password_verify('correct horse', $hash));
	}

	public function testDummyHashHasTheSameCostAsNewHashes(): void {
		$dummy = (string) (new ReflectionClassConstant(Auth::class, 'DUMMY_HASH'))->getValue();
		$info  = password_get_info($dummy);

		self::assertSame(PASSWORD_BCRYPT, $info['algo']);
		self::assertSame(password_get_info(Auth::hashPassword('x'))['options'], $info['options']);
	}

	public function testOlderCostNeedsRehash(): void {
		$options = (array) (new ReflectionClassConstant(Auth::class, 'HASH_OPTIONS'))->getValue();
		$cost10  = password_hash('secret12', PASSWORD_BCRYPT, ['cost' => 10]);

		self::assertTrue(password_needs_rehash($cost10, PASSWORD_BCRYPT, $options));
		self::assertFalse(password_needs_rehash(Auth::hashPassword('secret12'), PASSWORD_BCRYPT, $options));
	}

	public function testRejectsNullBytes(): void {
		self::assertTrue(Auth::isHashable('ordinary password'));
		self::assertTrue(Auth::isHashable(''));
		self::assertFalse(Auth::isHashable("pass\0word"));
		self::assertFalse(Auth::isHashable("\0"));
	}
}
