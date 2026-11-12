#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

/**
 * Background worker for the IMDb import: drains the queue (import_queue).
 * Started automatically by the import page, but can also be run manually
 * or via cron - as the web server user, so it shares the lock file:
 *   sudo -u www-data /usr/bin/php /var/www/catenvis/bin/process_imports.php
 *
 * A file lock in import.work_dir (default: the system temp directory)
 * ensures only one worker runs at a time.
 */

use Catenvis\Config;
use Catenvis\Database;
use Catenvis\Repository\ImportQueueRepository;
use Catenvis\Repository\SeriesRepository;
use Catenvis\Repository\UserRepository;
use Catenvis\Repository\WatchRepository;
use Catenvis\SeriesService;
use Catenvis\TmdbClient;
use Catenvis\TmdbException;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$config = Config::load($projectDir . '/config/config.php');

// Only one worker at a time.
$lockPath = $config->importWorkDir() . '/catenvis_import.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
	fwrite(STDERR, "Cannot open the lock file $lockPath. Run the worker as the web server user (e.g. sudo -u www-data) or check import.work_dir.\n");
	exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
	// A worker is already running - it will pick up new entries too.
	exit(0);
}
/** @var array<string, mixed> $dbConfig */
$dbConfig = $config->get('db', []);
$db = new Database($dbConfig);

/** @var array<string, mixed> $tmdbConfig */
$tmdbConfig = $config->get('tmdb', []);
$tmdb    = new TmdbClient($tmdbConfig);
$series  = new SeriesRepository($db);
$users   = new UserRepository($db);
$service = new SeriesService(
	$tmdb,
	$series,
	$users->activeContentLanguages($tmdb->baseLanguageCode()),
	$users->activeEpisodeLanguages($tmdb->baseLanguageCode())
);
$queue   = new ImportQueueRepository($db);
$watch   = new WatchRepository($db);

// We hold the lock, so no other worker is running: entries still marked as
// processing were left behind by a worker that was killed or crashed. They
// are failed rather than retried, so an entry that kills the worker every
// time cannot block the queue for good.
$queue->failStale();

$processed  = 0;
$lastUserId = 0;
while (($item = $queue->nextPending($lastUserId)) !== null) {
	$id         = (int) $item['id'];
	$userId     = (int) $item['user_id'];
	$lastUserId = $userId;
	$imdbId = (string) $item['imdb_id'];
	$queue->markProcessing($id);

	try {
		$match = $tmdb->findSeriesByImdbId($imdbId);
		if ($match === null || (int) ($match['id'] ?? 0) === 0) {
			$queue->setResult($id, 'notfound', 'No TMDB series found for this IMDb id.');
			continue;
		}
		$seriesId = (int) $match['id'];

		if ($series->followStatus($userId, $seriesId) !== null) {
			$queue->setResult($id, 'skipped', 'Already present.');
			continue;
		}

		$service->sync($seriesId);
		$series->setFollowStatus($userId, $seriesId, 'following');
		if ((int) ($item['mark_seen'] ?? 0) === 1) {
			$watch->markCompletedSeasons($userId, $seriesId);
		}
		$queue->setResult($id, 'done', (string) ($match['name'] ?? ''));
		$processed++;
	} catch (\Throwable $e) {
		// Shown on the import page: TMDB messages carry only endpoint and
		// status; anything else (database, internal) stays in the log.
		if ($e instanceof TmdbException) {
			$message = ImportQueueRepository::fitMessage($e->getMessage());
		} else {
			$message = 'Import failed.';
			fwrite(STDERR, sprintf("  Entry %d (%s): %s: %s\n", $id, $imdbId, $e::class, $e->getMessage()));
		}
		try {
			$queue->setResult($id, 'failed', $message);
		} catch (\Throwable $inner) {
			// The entry stays in processing; the next worker start fails it.
			fwrite(STDERR, $inner->getMessage() . "\n");
		}
	}

	// Ease the TMDB rate limit.
	usleep(200000);
}

flock($lock, LOCK_UN);
fclose($lock);

fwrite(STDOUT, sprintf("[%s] Import worker finished. Newly imported: %d.\n", date('Y-m-d H:i:s'), $processed));
exit(0);
