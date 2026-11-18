<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the splitting of the library search input and the LIKE patterns.
 */
final class SearchQueryTest extends TestCase {
	/**
	 * @param list<string> $expected
	 */
	#[DataProvider('termCases')]
	public function testTermsSplitsInput(string $input, array $expected): void {
		self::assertSame($expected, SearchQuery::terms($input));
	}

	/**
	 * @return iterable<string, array{string, list<string>}>
	 */
	public static function termCases(): iterable {
		yield 'empty'                 => ['', []];
		yield 'only spaces'           => ["  \t ", []];
		yield 'single word'           => ['trait', ['trait']];
		yield 'several words'         => ['traitors  celebrity', ['traitors', 'celebrity']];
		yield 'star is ignored'       => ['trait*', ['trait']];
		yield 'star separates'        => ['the*traitors', ['the', 'traitors']];
		yield 'only stars'            => ['**', []];
		yield 'duplicates dropped'    => ['the the end', ['the', 'end']];
		yield 'single character'      => ['V', ['V']];
		yield 'multibyte kept intact' => ['Señora  Acero', ['Señora', 'Acero']];
	}

	public function testTermsAreLimitedInCount(): void {
		$terms = SearchQuery::terms(implode(' ', range(1, SearchQuery::MAX_TERMS + 5)));

		self::assertCount(SearchQuery::MAX_TERMS, $terms);
		self::assertSame('1', $terms[0]);
	}

	public function testInputIsLimitedInLength(): void {
		$terms = SearchQuery::terms(str_repeat('ä', SearchQuery::MAX_LENGTH + 20));

		self::assertSame([str_repeat('ä', SearchQuery::MAX_LENGTH)], $terms);
	}

	public function testContainsPatternEscapesWildcards(): void {
		self::assertSame('%100!%!_x!!%', SearchQuery::containsPattern('100%_x!'));
	}

	public function testPrefixPatternJoinsTerms(): void {
		self::assertSame('the tra%', SearchQuery::prefixPattern(['the', 'tra']));
	}
}
