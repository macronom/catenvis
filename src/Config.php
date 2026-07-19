<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

use RuntimeException;

/**
 * Loads and wraps the application configuration from config/config.php.
 */
final class Config {
	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $values Configuration values.
	 */
	private function __construct(array $values) {
		$this->values = $values;
	}

	/**
	 * Loads the configuration from the given PHP file.
	 *
	 * @param string $path Absolute path to config.php.
	 */
	public static function load(string $path): self {
		if (!is_file($path)) {
			throw new RuntimeException(
				"Configuration file not found: $path. "
				. 'Copy config/config.sample.php to config/config.php and fill it in.'
			);
		}

		$values = require $path;
		if (!is_array($values)) {
			throw new RuntimeException('config.php must return an array.');
		}

		return new self($values);
	}

	/**
	 * Returns a value via dot notation (e.g. "db.host").
	 */
	public function get(string $key, mixed $default = null): mixed {
		$node = $this->values;
		foreach (explode('.', $key) as $segment) {
			if (!is_array($node) || !array_key_exists($segment, $node)) {
				return $default;
			}
			$node = $node[$segment];
		}

		return $node;
	}

	/**
	 * Checks whether the application runs in production mode.
	 */
	public function isProduction(): bool {
		return $this->get('environment') === 'production';
	}
}
