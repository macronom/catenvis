<?php

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\EpisodeTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers episode title selection (own -> English -> base fallback) and the
 * per-language detection of TMDB auto-placeholder titles ("Folge 5" & co).
 */
final class EpisodeTitleTest extends TestCase {
	/**
	 * @param list<array{0: string, 1: string}> $tiers
	 */
	#[DataProvider('pickCases')]
	public function testPick(array $tiers, int $episodeNumber, string $expected): void {
		self::assertSame($expected, EpisodeTitle::pick($tiers, $episodeNumber));
	}

	/**
	 * @return iterable<string, array{list<array{0: string, 1: string}>, int, string}>
	 */
	public static function pickCases(): iterable {
		yield 'own real title wins' => [
			[['Der Anfang', 'de'], ['The Beginning', 'en'], ['Der Anfang', 'de']], 1, 'Der Anfang',
		];
		yield 'own placeholder falls back to English' => [
			[['Folge 5', 'de'], ['The Heist', 'en'], ['Folge 5', 'de']], 5, 'The Heist',
		];
		yield 'own and English placeholders fall back to base' => [
			[['Folge 5', 'de'], ['Episode 5', 'en'], ['Echter Titel', 'de']], 5, 'Echter Titel',
		];
		yield 'all placeholders (the "Lucky" shape) yield empty' => [
			[['Folge 5', 'de'], ['', 'en'], ['Folge 5', 'de']], 5, '',
		];
		yield 'all empty yields empty' => [
			[['', 'de'], ['', 'en'], ['', 'de']], 1, '',
		];
		yield 'English fallback when own is empty' => [
			[['', 'de'], ['The Heist', 'en'], ['', 'de']], 3, 'The Heist',
		];
		// The user's key point: a German title "Episode 1" is real, because the
		// German placeholder word is "Folge" - it must not be blanked.
		yield 'German "Episode 1" is a real title, not a placeholder' => [
			[['Episode 1', 'de'], ['', 'en'], ['Episode 1', 'de']], 1, 'Episode 1',
		];
		yield 'genuine "Level 3" is not a placeholder' => [
			[['Level 3', 'de'], ['', 'en'], ['', 'de']], 3, 'Level 3',
		];
		yield 'number mismatch keeps "Folge 5" as a real title' => [
			[['Folge 5', 'de'], ['', 'en'], ['', 'de']], 3, 'Folge 5',
		];
	}

	#[DataProvider('placeholderCases')]
	public function testIsPlaceholder(string $name, int $episodeNumber, string $lang, bool $expected): void {
		self::assertSame($expected, EpisodeTitle::isPlaceholder($name, $episodeNumber, $lang));
	}

	/**
	 * @return iterable<string, array{string, int, string, bool}>
	 */
	public static function placeholderCases(): iterable {
		yield 'German Folge in de' => ['Folge 5', 5, 'de', true];
		yield 'English Episode in en' => ['Episode 5', 5, 'en', true];
		yield 'Spanish Episodio in es' => ['Episodio 5', 5, 'es', true];
		yield 'Italian Episodio in it' => ['Episodio 5', 5, 'it', true];
		yield 'French Épisode in fr (accented)' => ['Épisode 5', 5, 'fr', true];
		yield 'zero-padded number' => ['Folge 05', 5, 'de', true];
		// Cross-language: the crux of the fix.
		yield 'English word "Episode" in de is NOT a placeholder' => ['Episode 5', 5, 'de', false];
		yield 'German word "Folge" in en is NOT a placeholder' => ['Folge 5', 5, 'en', false];
		yield 'number does not match episode' => ['Folge 5', 3, 'de', false];
		yield 'unknown language cannot be checked' => ['Aflevering 5', 5, 'nl', false];
		yield 'Kapitel is a real title word' => ['Kapitel 1', 1, 'de', false];
		yield 'empty string' => ['', 1, 'de', false];
	}
}
