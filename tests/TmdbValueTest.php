<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\TmdbValue;
use PHPUnit\Framework\TestCase;

/**
 * Covers the checks applied to TMDB values before storing them.
 */
final class TmdbValueTest extends TestCase {
	public function testImagePathAcceptsTmdbPaths(): void {
		self::assertSame('/kqjL17yufvn9OVLyXYpvtyrFfak.jpg', TmdbValue::imagePath('/kqjL17yufvn9OVLyXYpvtyrFfak.jpg'));
		self::assertSame('/a-b_c.svg', TmdbValue::imagePath('/a-b_c.svg'));
	}

	public function testImagePathRejectsEverythingElse(): void {
		foreach ([null, '', 'abc.jpg', '/', '/../x.jpg', '//evil.example/x.jpg', '/a/b.jpg', '/x.jpg?y=1', "/x.jpg\n", '/x".jpg', 42] as $value) {
			self::assertNull(TmdbValue::imagePath($value), var_export($value, true));
		}
		self::assertNull(TmdbValue::imagePath('/' . str_repeat('a', 201)));
	}

	public function testImdbId(): void {
		self::assertSame('tt0944947', TmdbValue::imdbId('tt0944947'));
		self::assertNull(TmdbValue::imdbId('tt' . str_repeat('1', 13)));
		self::assertNull(TmdbValue::imdbId('nm0000001'));
		self::assertNull(TmdbValue::imdbId(''));
		self::assertNull(TmdbValue::imdbId(null));
	}

	public function testLanguageCode(): void {
		self::assertSame('en', TmdbValue::languageCode('en'));
		self::assertSame('cmn', TmdbValue::languageCode('cmn'));
		self::assertNull(TmdbValue::languageCode('EN'));
		self::assertNull(TmdbValue::languageCode('en-US'));
		self::assertNull(TmdbValue::languageCode(null));
	}

	public function testTextCutsByCharacters(): void {
		self::assertSame('Dark', TmdbValue::text('Dark'));
		self::assertSame(str_repeat('ü', 255), TmdbValue::text(str_repeat('ü', 300)));
		self::assertSame('ab', TmdbValue::text('abc', 2));
		self::assertSame('', TmdbValue::text("bad\xFF"));
		self::assertSame('', TmdbValue::text(null));
	}

	public function testLongTextCutsByBytesWithoutSplittingCharacters(): void {
		$cut = TmdbValue::longText(str_repeat('€', 30000));
		self::assertIsString($cut);
		self::assertLessThanOrEqual(TmdbValue::TEXT_BYTES, strlen($cut));
		self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
		self::assertSame(intdiv(TmdbValue::TEXT_BYTES, 3), mb_strlen($cut));

		self::assertSame('Plot.', TmdbValue::longText('Plot.'));
		self::assertNull(TmdbValue::longText(''));
		self::assertNull(TmdbValue::longText("bad\xFF"));
		self::assertNull(TmdbValue::longText(7));
	}
}
