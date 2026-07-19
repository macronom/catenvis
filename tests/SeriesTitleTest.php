<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\SeriesTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the title selection logic and its fallback chains
 * (own language -> English -> original, plus the "original" mode).
 */
final class SeriesTitleTest extends TestCase {
	/**
	 * @param array<string, mixed> $row
	 */
	#[DataProvider('titleCases')]
	public function testPickSelectsExpectedTitle(array $row, string $lang, string $userLang, string $expected): void {
		self::assertSame($expected, SeriesTitle::pick($row, $lang, $userLang));
	}

	/**
	 * The third argument defaults to 'en', so in the "original" mode an
	 * English production keeps its translated name without an explicit userLang.
	 */
	public function testPickUsesEnglishAsDefaultUserLanguage(): void {
		$row = ['name' => 'Translated', 'original_name' => 'Original', 'original_language' => 'en'];

		self::assertSame('Translated', SeriesTitle::pick($row, 'original'));
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, string, string, string}>
	 */
	public static function titleCases(): iterable {
		// --- own-language mode ($lang != 'original'): name -> title_en -> original_name ---
		yield 'own language present wins' => [
			['name' => 'Der Herr der Ringe', 'title_en' => 'The Lord of the Rings', 'original_name' => 'The Lord of the Rings', 'original_language' => 'en'],
			'de', 'de', 'Der Herr der Ringe',
		];

		yield 'falls back to English when own translation missing' => [
			['name' => '', 'title_en' => 'The Lord of the Rings', 'original_name' => 'Original', 'original_language' => 'en'],
			'de', 'de', 'The Lord of the Rings',
		];

		yield 'falls back to original name when own and English missing' => [
			['name' => '', 'title_en' => '', 'original_name' => 'Original Name', 'original_language' => 'ja'],
			'de', 'de', 'Original Name',
		];

		yield 'returns empty string when the row is empty' => [
			[],
			'de', 'de', '',
		];

		// --- "original" mode, own-language production: name -> original_name ---
		yield 'original mode keeps translated title for own-language production' => [
			['name' => 'Deutscher Titel', 'original_name' => 'Origineel', 'original_language' => 'de'],
			'original', 'de', 'Deutscher Titel',
		];

		yield 'original mode own-language falls back to original name when translation missing' => [
			['name' => '', 'original_name' => 'Origineel', 'original_language' => 'nl'],
			'original', 'nl', 'Origineel',
		];

		// --- "original" mode, foreign production: title_en -> original_name -> name ---
		yield 'original mode prefers English then original for a foreign production' => [
			['name' => 'Übersetzt', 'title_en' => 'English Title', 'original_name' => 'Original', 'original_language' => 'en'],
			'original', 'de', 'English Title',
		];

		yield 'original mode foreign falls back to original name when English missing' => [
			['name' => 'Übersetzt', 'title_en' => '', 'original_name' => 'Original', 'original_language' => 'ja'],
			'original', 'de', 'Original',
		];

		yield 'original mode foreign uses translated name as last resort' => [
			['name' => 'Übersetzt', 'title_en' => '', 'original_name' => '', 'original_language' => 'ja'],
			'original', 'de', 'Übersetzt',
		];

		yield 'original mode returns empty string when nothing is set' => [
			['original_language' => 'de'],
			'original', 'de', '',
		];
	}
}
