<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the pure parts of the migration runner: splitting SQL scripts into
 * single statements (quotes, comments) and discovering migration files in
 * order. The DB-touching methods need a live MySQL and are not tested here.
 */
final class MigratorTest extends TestCase {
	private ?string $tempDir = null;

	protected function tearDown(): void {
		if ($this->tempDir !== null && is_dir($this->tempDir)) {
			foreach (glob($this->tempDir . '/*') ?: [] as $entry) {
				if (is_dir($entry)) {
					@rmdir($entry);
				} else {
					@unlink($entry);
				}
			}
			@rmdir($this->tempDir);
		}
		$this->tempDir = null;
	}

	/**
	 * @param list<string> $expected
	 */
	#[DataProvider('splitCases')]
	public function testSplitStatements(string $sql, array $expected): void {
		self::assertSame($expected, Migrator::splitStatements($sql));
	}

	/**
	 * @return iterable<string, array{string, list<string>}>
	 */
	public static function splitCases(): iterable {
		yield 'single statement without trailing semicolon' => [
			'SELECT 1',
			['SELECT 1'],
		];

		yield 'two statements are split and trimmed' => [
			"CREATE TABLE a (x INT);\nCREATE TABLE b (y INT);",
			['CREATE TABLE a (x INT)', 'CREATE TABLE b (y INT)'],
		];

		yield 'trailing semicolon and whitespace produce no empty tail' => [
			"SELECT 1;\n\t \n",
			['SELECT 1'],
		];

		yield 'semicolon inside single-quoted string does not split' => [
			"INSERT INTO t VALUES ('a;b');",
			["INSERT INTO t VALUES ('a;b')"],
		];

		yield 'semicolon inside double-quoted string does not split' => [
			'SELECT "x;y";',
			['SELECT "x;y"'],
		];

		yield 'backslash-escaped quote keeps the string open' => [
			"INSERT INTO t VALUES ('a\\';b');",
			["INSERT INTO t VALUES ('a\\';b')"],
		];

		yield 'doubled quote is an escaped quote, not the end' => [
			"SELECT 'a''b;c';",
			["SELECT 'a''b;c'"],
		];

		yield 'line comment with semicolon is stripped' => [
			"SELECT 1; -- trailing; comment\nSELECT 2;",
			['SELECT 1', 'SELECT 2'],
		];

		yield 'double dash without whitespace is not a comment' => [
			'SELECT 1--2;',
			['SELECT 1--2'],
		];

		yield 'hash comment is stripped' => [
			"# leading; comment\nSELECT 1;",
			['SELECT 1'],
		];

		yield 'block comment with semicolon is stripped' => [
			"/* block;\ncomment */SELECT 1;",
			['SELECT 1'],
		];

		yield 'backtick identifier containing semicolon' => [
			'SELECT `a;b` FROM t;',
			['SELECT `a;b` FROM t'],
		];

		yield 'empty input' => ['', []];

		yield 'whitespace-only input' => ["  \n\t ", []];

		yield 'comment-only input' => ["-- nothing here\n/* really; nothing */", []];
	}

	public function testDiscoverMigrationsReturnsSortedBasenames(): void {
		$dir = $this->makeTempDir();
		file_put_contents($dir . '/002_second.sql', 'SELECT 2;');
		file_put_contents($dir . '/010_tenth.sql', 'SELECT 10;');
		file_put_contents($dir . '/001_first.sql', 'SELECT 1;');

		self::assertSame(
			['001_first.sql', '002_second.sql', '010_tenth.sql'],
			Migrator::discoverMigrations($dir)
		);
	}

	public function testDiscoverMigrationsIgnoresOtherFilesAndDirectories(): void {
		$dir = $this->makeTempDir();
		file_put_contents($dir . '/001_first.sql', 'SELECT 1;');
		file_put_contents($dir . '/README.md', 'docs');
		mkdir($dir . '/subdir.sql'); // Directory despite the suffix.

		self::assertSame(['001_first.sql'], Migrator::discoverMigrations($dir));
	}

	public function testDiscoverMigrationsReturnsEmptyListForEmptyDirectory(): void {
		self::assertSame([], Migrator::discoverMigrations($this->makeTempDir()));
	}

	public function testDiscoverMigrationsThrowsForMissingDirectory(): void {
		$this->expectException(RuntimeException::class);

		Migrator::discoverMigrations(sys_get_temp_dir() . '/catenvis-does-not-exist-' . uniqid());
	}

	private function makeTempDir(): string {
		$dir = sys_get_temp_dir() . '/catenvis-mig-' . uniqid();
		mkdir($dir, 0777, true);
		$this->tempDir = $dir;

		return $dir;
	}
}
