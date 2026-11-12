<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Repository\ImportQueueRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers fitting worker messages into import_queue.message: cut by
 * characters (not bytes) to the column width, invalid UTF-8 replaced.
 */
final class ImportQueueMessageTest extends TestCase {
	public function testKeepsShortMessages(): void {
		self::assertSame('Already present.', ImportQueueRepository::fitMessage('Already present.'));
	}

	public function testCutsToTheColumnWidthInCharacters(): void {
		$fitted = ImportQueueRepository::fitMessage(str_repeat('ä', 300));

		self::assertSame(str_repeat('ä', ImportQueueRepository::MESSAGE_LENGTH), $fitted);
	}

	public function testKeepsMultiLineMessages(): void {
		self::assertSame("a\nb", ImportQueueRepository::fitMessage("a\nb"));
	}

	public function testReplacesInvalidUtf8(): void {
		self::assertSame('Import failed.', ImportQueueRepository::fitMessage("SQLSTATE \xFF"));
	}
}
