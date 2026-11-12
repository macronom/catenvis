<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Controller\ImportController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the IMDb CSV parser of the import: series rows only, blank lines
 * skipped, BOM stripped, RFC 4180 quoting, and no PHP notices (fgetcsv's
 * implicit escape argument is deprecated as of PHP 8.4).
 */
final class ImportCsvParserTest extends TestCase {
	public function testParsesSeriesRowsAndSkipsBlankLines(): void {
		$csv = "\n\xEF\xBB\xBFConst,Title,Title Type\n"
			. "tt0001,\"Show, with comma\",TV Series\n"
			. "\n"
			. "tt0002,A movie,Movie\n"
			. "tt0003,\"Back\\\\slash \"\"quoted\"\"\",tvMiniSeries\n"
			. "\n";

		self::assertSame(
			['tt0001' => 'Show, with comma', 'tt0003' => 'Back\\\\slash "quoted"'],
			$this->parse($csv)
		);
	}

	public function testRejectsFilesWithoutTheRequiredColumns(): void {
		self::assertSame([], $this->parse("Title,Year\nFoo,2020\n"));
		self::assertSame([], $this->parse("\n\n"));
	}

	public function testRaisesNoNotices(): void {
		$notices = [];
		set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
			$notices[] = $message;

			return true;
		});
		$previous = error_reporting(E_ALL);
		try {
			$this->parse("Const,Title,Title Type\n\ntt0001,Show,TV Series\n");
		} finally {
			error_reporting($previous);
			restore_error_handler();
		}

		self::assertSame([], $notices);
	}

	/**
	 * Runs the private parser on the given CSV content.
	 *
	 * @return array<string, string>
	 */
	private function parse(string $content): array {
		$path = tempnam(sys_get_temp_dir(), 'csv');
		self::assertIsString($path);
		file_put_contents($path, $content);
		try {
			$class = new ReflectionClass(ImportController::class);
			/** @var array<string, string> $result */
			$result = $class->getMethod('parseImdbCsv')->invoke($class->newInstanceWithoutConstructor(), $path);
		} finally {
			unlink($path);
		}

		return $result;
	}
}
