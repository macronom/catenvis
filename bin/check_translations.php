#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

/**
 * Checks the UI translation catalogs (lang/*.json) for completeness:
 * extracts all $t()/->t() source texts from templates and source code and
 * reports missing and orphaned entries per catalog. Exits non-zero when a
 * catalog has missing entries.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);

// Keys used through variables (not extractable via the literal regex below).
$dynamicKeys = [
	// Month names used by the shared $shortDate helper (src/App.php).
	'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
	// App::STATUS_TAGS labels (translated via App::seriesStatusTag()).
	'Running', 'In production', 'Planned', 'Pilot', 'Ended', 'Canceled',
	// Import worker messages stored in import_queue.message ($t($r['message'])).
	'No TMDB series found for this IMDb id.', 'Already present.',
];

$files = array_merge(
	glob($projectDir . '/templates/*.tpl.php') ?: [],
	glob($projectDir . '/templates/_partials/*.tpl.php') ?: [],
	glob($projectDir . '/src/*.php') ?: [],
	glob($projectDir . '/src/Controller/*.php') ?: [],
	glob($projectDir . '/src/Repository/*.php') ?: [],
	[$projectDir . '/html/index.php']
);

$keys = array_fill_keys($dynamicKeys, true);
foreach ($files as $file) {
	$code = (string) file_get_contents($file);
	// First single-quoted argument of $t(...) / ->t(...).
	if (preg_match_all("/(?:\\\$t|->t)\\s*\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $matches)) {
		foreach ($matches[1] as $raw) {
			// Single-quoted PHP strings only escape \' and \\.
			$keys[str_replace(["\\'", '\\\\'], ["'", '\\'], $raw)] = true;
		}
	}
}
$sourceKeys = array_keys($keys);
sort($sourceKeys);
printf("Source texts found: %d\n", count($sourceKeys));

$exitCode = 0;
foreach (glob($projectDir . '/lang/*.json') ?: [] as $catalogFile) {
	$lang    = basename($catalogFile, '.json');
	$decoded = json_decode((string) file_get_contents($catalogFile), true);
	if (!is_array($decoded)) {
		printf("%s: INVALID JSON\n", $lang);
		$exitCode = 1;
		continue;
	}

	// Keys like "__language__" are catalog metadata, not translations.
	$catalogKeys = array_filter(array_keys($decoded), static fn(string $k): bool => !str_starts_with($k, '__'));
	$missing = array_diff($sourceKeys, $catalogKeys);
	$orphans = array_diff($catalogKeys, $sourceKeys);

	printf("%s: %d entries, %d missing, %d orphaned\n", $lang, count($decoded), count($missing), count($orphans));
	foreach ($missing as $key) {
		printf("  missing:  %s\n", $key);
	}
	foreach ($orphans as $key) {
		printf("  orphaned: %s\n", $key);
	}
	if ($missing !== []) {
		$exitCode = 1;
	}
}

exit($exitCode);
