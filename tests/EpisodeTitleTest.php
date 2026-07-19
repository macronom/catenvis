<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

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
	public function testPick(array $tiers, string $expected): void {
		self::assertSame($expected, EpisodeTitle::pick($tiers));
	}

	/**
	 * @return iterable<string, array{list<array{0: string, 1: string}>, string}>
	 */
	public static function pickCases(): iterable {
		yield 'own real title wins' => [
			[['Der Anfang', 'de'], ['The Beginning', 'en'], ['Der Anfang', 'de']], 'Der Anfang',
		];
		yield 'own placeholder falls back to English' => [
			[['Folge 5', 'de'], ['The Heist', 'en'], ['Folge 5', 'de']], 'The Heist',
		];
		yield 'own and English placeholders fall back to base' => [
			[['Folge 5', 'de'], ['Episode 5', 'en'], ['Echter Titel', 'de']], 'Echter Titel',
		];
		yield 'all placeholders (the "Lucky" shape) yield empty' => [
			[['Folge 5', 'de'], ['', 'en'], ['Folge 5', 'de']], '',
		];
		yield 'all empty yields empty' => [
			[['', 'de'], ['', 'en'], ['', 'de']], '',
		];
		yield 'English fallback when own is empty' => [
			[['', 'de'], ['The Heist', 'en'], ['', 'de']], 'The Heist',
		];
		// The user's key point: a German title "Episode 1" is real, because the
		// German placeholder word is "Folge" - it must not be blanked.
		yield 'German "Episode 1" is a real title, not a placeholder' => [
			[['Episode 1', 'de'], ['', 'en'], ['Episode 1', 'de']], 'Episode 1',
		];
		yield 'genuine "Level 3" is not a placeholder' => [
			[['Level 3', 'de'], ['', 'en'], ['', 'de']], 'Level 3',
		];
		// Babylon Berlin S2E1: German "Folge 1" plus TMDB's absolutely numbered
		// English placeholder "Episode 9" - both must be blanked to the code.
		yield 'absolute-numbered English placeholder does not leak (S2+)' => [
			[['Folge 1', 'de'], ['Episode 9', 'en'], ['Folge 1', 'de']], '',
		];
	}

	#[DataProvider('placeholderCases')]
	public function testIsPlaceholder(string $name, string $lang, bool $expected): void {
		self::assertSame($expected, EpisodeTitle::isPlaceholder($name, $lang));
	}

	/**
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function placeholderCases(): iterable {
		yield 'German Folge in de' => ['Folge 5', 'de', true];
		yield 'English Episode in en' => ['Episode 5', 'en', true];
		yield 'Spanish Episodio in es' => ['Episodio 5', 'es', true];
		yield 'Italian Episodio in it' => ['Episodio 5', 'it', true];
		yield 'French Épisode in fr (accented)' => ['Épisode 5', 'fr', true];
		yield 'zero-padded number' => ['Folge 05', 'de', true];
		// The number need not match the episode position: TMDB numbers English
		// placeholders absolutely across seasons ("Episode 9" for S2E1).
		yield 'absolute-numbered English placeholder' => ['Episode 9', 'en', true];
		// Cross-language: the crux of the per-language word table.
		yield 'English word "Episode" in de is NOT a placeholder' => ['Episode 5', 'de', false];
		yield 'German word "Folge" in en is NOT a placeholder' => ['Folge 5', 'en', false];
		yield 'unknown language cannot be checked' => ['Aflevering 5', 'nl', false];
		yield 'Kapitel is a real title word' => ['Kapitel 1', 'de', false];
		yield 'empty string' => ['', 'de', false];
	}
}
