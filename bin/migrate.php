#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

/**
 * Applies pending database migrations from sql/migrations/ (see the README
 * there). Idempotent and safe to re-run: already applied migrations are
 * tracked in the schema_migrations table and skipped.
 *
 * Usage:
 *   php bin/migrate.php            # apply all pending migrations
 *   php bin/migrate.php --status   # read-only: show applied/pending state
 */

use Catenvis\Config;
use Catenvis\Database;
use Catenvis\Migrator;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$status = false;
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--status') {
		$status = true;
		continue;
	}
	fwrite(STDERR, "Unknown argument: $arg\nUsage: php bin/migrate.php [--status]\n");
	exit(1);
}

$config = Config::load($projectDir . '/config/config.php');
/** @var array<string, mixed> $dbConfig */
$dbConfig = $config->get('db', []);
$migrator = new Migrator(new Database($dbConfig), $projectDir . '/sql/migrations');

try {
	// Applied-but-missing files hint at a renamed migration - warn, never fail.
	foreach ($migrator->missingMigrations() as $name) {
		fwrite(STDERR, "Warning: migration $name is recorded as applied but missing on disk.\n");
	}

	if ($status) {
		printf("Applied: %d\n", count($migrator->appliedMigrations()));
		$pending = $migrator->pendingMigrations();
		if ($pending === []) {
			echo "Nothing pending.\n";
		} else {
			echo "Pending:\n";
			foreach ($pending as $name) {
				echo "  $name\n";
			}
		}
		exit(0);
	}

	$applied = $migrator->migrate(static function (string $name): void {
		printf("Applied %s\n", $name);
	});
	echo $applied === [] ? "Nothing to apply.\n" : sprintf("%d migration(s) applied.\n", count($applied));
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
