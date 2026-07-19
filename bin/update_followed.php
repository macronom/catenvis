#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

/**
 * Daily cron: refreshes every series followed by at least one user from the
 * TMDB API (new episodes, seasons, last/next air date).
 *
 * Example crontab entry:
 *   30 4 * * * /usr/bin/php /var/www/catenvis/bin/update_followed.php >> /var/www/catenvis/catenvis.log 2>&1
 */

use Catenvis\Config;
use Catenvis\Database;
use Catenvis\Repository\SeriesRepository;
use Catenvis\Repository\UserRepository;
use Catenvis\SeriesService;
use Catenvis\TmdbClient;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$config = Config::load($projectDir . '/config/config.php');

/** @var array<string, mixed> $dbConfig */
$dbConfig = $config->get('db', []);
$db = new Database($dbConfig);

/** @var array<string, mixed> $tmdbConfig */
$tmdbConfig = $config->get('tmdb', []);
$repo         = new SeriesRepository($db);
$users        = new UserRepository($db);
$tmdb         = new TmdbClient($tmdbConfig);
$langs        = $users->activeContentLanguages($tmdb->baseLanguageCode());
$episodeLangs = $users->activeEpisodeLanguages($tmdb->baseLanguageCode());
$service      = new SeriesService($tmdb, $repo, $langs, $episodeLangs);

// "--all" refreshes every followed series; "--all-series" additionally covers
// stopped ones (for a one-off backfill of a new episode field across the whole
// history). Otherwise only the series due today (staggered / missing language).
// "--force" additionally re-fetches every season in every active language
// (to backfill fields added later, e.g. episode overviews or runtime).
$argv      = $_SERVER['argv'] ?? [];
$allSeries = in_array('--all-series', $argv, true);
$all       = in_array('--all', $argv, true);
$force     = in_array('--force', $argv, true);
if ($allSeries) {
	$seriesIds = $repo->allSeriesIds();
	$scope     = 'all';
} elseif ($all) {
	$seriesIds = $repo->followedSeriesIds();
	$scope     = 'followed';
} else {
	$seriesIds = $repo->seriesDueForRefresh($langs, $episodeLangs);
	$scope     = 'due';
}
$now = date('Y-m-d H:i:s');

printf("[%s] Updating %d %s series.\n", $now, count($seriesIds), $scope);

$ok = 0;
$failed = 0;
$gone = 0;
foreach ($seriesIds as $seriesId) {
	try {
		$refreshed = $service->sync($seriesId, $force);
		printf("  Series %d: %d season(s) refreshed.\n", $seriesId, $refreshed);
		$ok++;
	} catch (\Catenvis\TmdbException $e) {
		if ($e->isGone()) {
			// SeriesService already flagged it; it drops out of the nightly
			// refresh and is surfaced to its followers on the dashboard.
			fwrite(STDERR, sprintf("  Series %d unavailable (gone), marked and skipped from now on: %s\n", $seriesId, $e->getMessage()));
			$gone++;
		} else {
			fwrite(STDERR, sprintf("  Series %d ERROR (will retry): %s\n", $seriesId, $e->getMessage()));
			$failed++;
		}
	} catch (\Throwable $e) {
		fwrite(STDERR, sprintf("  Series %d ERROR: %s\n", $seriesId, $e->getMessage()));
		$failed++;
	}
	// Ease the TMDB rate limit.
	usleep(250000);
}

printf("[%s] Done. Successful: %d, errors: %d, newly unavailable: %d.\n", date('Y-m-d H:i:s'), $ok, $failed, $gone);
exit($failed > 0 ? 1 : 0);
