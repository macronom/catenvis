<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers configuration loading and access: load() error handling,
 * dot-notation lookup with defaults, and isDevelopment().
 */
final class ConfigTest extends TestCase {
	/** @var list<string> Temp fixture files to clean up. */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			@unlink($file);
		}
		$this->tempFiles = [];
	}

	public function testLoadThrowsWhenFileMissing(): void {
		$this->expectException(RuntimeException::class);

		Config::load(sys_get_temp_dir() . '/catenvis-does-not-exist-' . __LINE__ . '.php');
	}

	public function testLoadThrowsWhenFileDoesNotReturnArray(): void {
		$path = $this->writeConfigFile('<?php return 42;');

		$this->expectException(RuntimeException::class);

		Config::load($path);
	}

	public function testGetReturnsTopLevelValue(): void {
		$config = $this->makeConfig(['base_url' => '/catenvis']);

		self::assertSame('/catenvis', $config->get('base_url'));
	}

	public function testGetResolvesNestedValueViaDotNotation(): void {
		$config = $this->makeConfig(['db' => ['host' => '127.0.0.1', 'port' => 3306]]);

		self::assertSame('127.0.0.1', $config->get('db.host'));
		self::assertSame(3306, $config->get('db.port'));
	}

	public function testGetReturnsWholeNestedArray(): void {
		$config = $this->makeConfig(['db' => ['host' => '127.0.0.1']]);

		self::assertSame(['host' => '127.0.0.1'], $config->get('db'));
	}

	public function testGetReturnsDefaultForMissingKey(): void {
		$config = $this->makeConfig(['db' => ['host' => '127.0.0.1']]);

		self::assertNull($config->get('missing'));
		self::assertSame('fallback', $config->get('missing', 'fallback'));
		self::assertSame('fallback', $config->get('db.missing', 'fallback'));
	}

	public function testGetReturnsDefaultWhenTraversingIntoNonArray(): void {
		$config = $this->makeConfig(['db' => 'not-an-array']);

		self::assertSame('fallback', $config->get('db.host', 'fallback'));
	}

	public function testIsDevelopmentOnlyForTheExactValue(): void {
		self::assertTrue($this->makeConfig(['environment' => 'development'])->isDevelopment());
	}

	public function testAnythingElseMeansProduction(): void {
		self::assertFalse($this->makeConfig(['environment' => 'production'])->isDevelopment());
		self::assertFalse($this->makeConfig([])->isDevelopment());
		self::assertFalse($this->makeConfig(['environment' => 'Development'])->isDevelopment());
		self::assertFalse($this->makeConfig(['environment' => 'development '])->isDevelopment());
		self::assertFalse($this->makeConfig(['environment' => 'dev'])->isDevelopment());
		self::assertFalse($this->makeConfig(['environment' => true])->isDevelopment());
	}

	public function testImportWorkDirDefaultsToTheTempDirectory(): void {
		$temp = rtrim(sys_get_temp_dir(), '/');

		self::assertSame($temp, $this->makeConfig([])->importWorkDir());
		self::assertSame($temp, $this->makeConfig(['import' => ['work_dir' => '  ']])->importWorkDir());
	}

	public function testImportWorkDirUsesTheConfiguredDirectoryWithoutTrailingSlash(): void {
		self::assertSame('/var/lib/catenvis', $this->makeConfig(['import' => ['work_dir' => '/var/lib/catenvis/']])->importWorkDir());
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function makeConfig(array $values): Config {
		return Config::load($this->writeConfigFile('<?php return ' . var_export($values, true) . ';'));
	}

	private function writeConfigFile(string $php): string {
		$path = tempnam(sys_get_temp_dir(), 'catenvis-cfg-');
		self::assertIsString($path);
		file_put_contents($path, $php);
		$this->tempFiles[] = $path;

		return $path;
	}
}
