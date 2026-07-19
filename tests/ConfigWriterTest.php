<?php

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\ConfigWriter;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the generated config file is valid PHP and round-trips the exact
 * configuration array, including awkward characters in secrets.
 */
final class ConfigWriterTest extends TestCase {
	public function testRendersRoundTrippingConfig(): void {
		$config = [
			'environment' => 'production',
			'base_url'    => '/catenvis',
			'php_binary'  => '/usr/bin/php',
			'session'     => ['lifetime' => 2592000],
			'db'          => [
				'host'     => '127.0.0.1',
				'port'     => 3306,
				'database' => 'catenvis',
				'user'     => 'catenvis',
				// Quotes, backslash and a dollar sign must survive verbatim.
				'password' => 'p\'a"s\\s$1',
				'charset'  => 'utf8mb4',
			],
			'tmdb'        => [
				'read_access_token' => 'tok-with-"quote"',
				'api_key'           => '',
				'language'          => 'en-US',
			],
			'update'      => ['stale_after_days' => 3],
		];

		$source = ConfigWriter::render($config);
		self::assertStringStartsWith('<?php', $source);

		$file = (string) tempnam(sys_get_temp_dir(), 'cfg');
		file_put_contents($file, $source);
		/** @var array<string, mixed> $loaded */
		$loaded = require $file;
		unlink($file);

		self::assertSame($config, $loaded);
	}
}
