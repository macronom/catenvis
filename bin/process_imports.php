#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

/**
 * Background worker for the IMDb import: drains the queue (import_queue).
 * Started automatically by the import page, but can also be run manually
 * or via cron, as a user that can read the configuration, e.g.:
 *   sudo -u www-data /usr/bin/php /var/www/catenvis/bin/process_imports.php
 *
 * A lock in the database ensures that only one worker runs at a time, also
 * when several hosts share the database.
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
use Catenvis\TmdbValue;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$config = Config::load($projectDir . '/config/config.php');

/** @var array<string, mixed> $dbConfig */
$dbConfig = $config->get('db', []);
$db    = new Database($dbConfig);
$queue = new ImportQueueRepository($db);

// Only one worker at a time.
if (!$queue->acquireWorkerLock()) {
	// A worker is already running - it will pick up new entries too.
	exit(0);
}

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
	if (!$queue->markProcessing($id)) {
		// Taken or cancelled meanwhile.
		continue;
	}

	try {
		$match = $tmdb->findSeriesByImdbId($imdbId);
		if ($match === null || (int) ($match['id'] ?? 0) === 0) {
			$queue->setResult($id, 'notfound', 'No TMDB series found for this IMDb id.');
		} elseif ($series->followStatus($userId, (int) $match['id']) !== null) {
			$queue->setResult($id, 'skipped', 'Already present.');
		} else {
			$seriesId = (int) $match['id'];
			$service->sync($seriesId);
			$series->setFollowStatus($userId, $seriesId, 'following');
			if ((int) ($item['mark_seen'] ?? 0) === 1) {
				$watch->markCompletedSeasons($userId, $seriesId);
			}
			$queue->setResult($id, 'done', TmdbValue::text($match['name'] ?? ''));
			$processed++;
		}
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

	// Ease the TMDB rate limit (every TMDB request, whatever its result).
	usleep(200000);
}

$queue->releaseWorkerLock();

fwrite(STDOUT, sprintf("[%s] Import worker finished. Newly imported: %d.\n", date('Y-m-d H:i:s'), $processed));
exit(0);
