<?php

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\TmdbClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, static language-code extraction from a BCP 47 tag.
 * (The network methods of TmdbClient are integration concerns and not
 * unit-tested here.)
 */
final class TmdbClientTest extends TestCase {
	#[DataProvider('languageCases')]
	public function testLanguageCode(string $input, string $expected): void {
		self::assertSame($expected, TmdbClient::languageCode($input));
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function languageCases(): iterable {
		yield 'BCP 47 tag reduced to ISO-639-1' => ['en-US', 'en'];
		yield 'German region tag' => ['de-DE', 'de'];
		yield 'Portuguese Brazil' => ['pt-BR', 'pt'];
		yield 'bare code passes through' => ['fr', 'fr'];
		yield 'uppercase input is lowercased' => ['ES', 'es'];
		yield 'empty string falls back to en' => ['', 'en'];
	}
}
