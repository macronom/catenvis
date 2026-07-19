<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Covers UI translation: catalog lookup with fallback to the English
 * source (missing entry / file / broken JSON), metadata and empty-value
 * filtering, and vsprintf argument handling including positional order.
 */
final class TranslatorTest extends TestCase {
	private string $projectDir;

	protected function setUp(): void {
		$this->projectDir = sys_get_temp_dir() . '/catenvis-tr-' . uniqid();
		mkdir($this->projectDir . '/lang', 0777, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->projectDir . '/lang/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->projectDir . '/lang');
		@rmdir($this->projectDir);
	}

	public function testEnglishSourceIsReturnedAsIs(): void {
		$translator = new Translator($this->projectDir, 'en');

		self::assertSame('Hello', $translator->translate('Hello'));
	}

	public function testReturnsTranslationFromCatalog(): void {
		$this->writeCatalog('de', ['Hello' => 'Hallo']);
		$translator = new Translator($this->projectDir, 'de');

		self::assertSame('Hallo', $translator->translate('Hello'));
	}

	public function testFallsBackToSourceForMissingEntry(): void {
		$this->writeCatalog('de', ['Hello' => 'Hallo']);
		$translator = new Translator($this->projectDir, 'de');

		self::assertSame('Goodbye', $translator->translate('Goodbye'));
	}

	public function testFallsBackToSourceWhenCatalogFileMissing(): void {
		$translator = new Translator($this->projectDir, 'fr');

		self::assertSame('Hello', $translator->translate('Hello'));
	}

	public function testFallsBackToSourceOnBrokenJson(): void {
		file_put_contents($this->projectDir . '/lang/de.json', '{ this is not valid json');
		$translator = new Translator($this->projectDir, 'de');

		// The translator logs the broken catalog via error_log; redirect that
		// to a temp file so it does not clutter the test/CI output.
		$previous = ini_set('error_log', $this->projectDir . '/lang/error.log');
		try {
			self::assertSame('Hello', $translator->translate('Hello'));
		} finally {
			if ($previous !== false) {
				ini_set('error_log', $previous);
			}
		}
	}

	public function testMetadataKeysAreNotTranslated(): void {
		$this->writeCatalog('de', ['__language__' => 'Deutsch', 'Hello' => 'Hallo']);
		$translator = new Translator($this->projectDir, 'de');

		self::assertSame('__language__', $translator->translate('__language__'));
		self::assertSame('Hallo', $translator->translate('Hello'));
	}

	public function testEmptyTranslationValueFallsBackToSource(): void {
		$this->writeCatalog('de', ['Hello' => '']);
		$translator = new Translator($this->projectDir, 'de');

		self::assertSame('Hello', $translator->translate('Hello'));
	}

	public function testAppliesVsprintfArguments(): void {
		$this->writeCatalog('de', ['%d new episodes' => '%d neue Folgen']);
		$translator = new Translator($this->projectDir, 'de');

		self::assertSame('3 neue Folgen', $translator->translate('%d new episodes', 3));
	}

	public function testSupportsPositionalPlaceholdersForWordOrder(): void {
		$this->writeCatalog('de', ['%1$s %2$d' => '%2$d. %1$s']);
		$translator = new Translator($this->projectDir, 'de');

		self::assertSame('16. Jan', $translator->translate('%1$s %2$d', 'Jan', 16));
	}

	public function testAppliesArgumentsToSourceTextWhenUntranslated(): void {
		$translator = new Translator($this->projectDir, 'en');

		self::assertSame('5 items', $translator->translate('%d items', 5));
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function writeCatalog(string $lang, array $entries): void {
		file_put_contents(
			$this->projectDir . '/lang/' . $lang . '.json',
			(string) json_encode($entries, JSON_UNESCAPED_UNICODE)
		);
	}
}
