#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Background worker for the IMDb import: drains the queue (import_queue).
 * Started automatically by the import page, but can also be run manually
 * or via cron:
 *   /usr/bin/php /var/www/catenvis/bin/process_imports.php
 *
 * A file lock ensures only one worker runs at a time.
 */

use Catenvis\Config;
use Catenvis\Database;
use Catenvis\Repository\ImportQueueRepository;
use Catenvis\Repository\SeriesRepository;
use Catenvis\Repository\UserRepository;
use Catenvis\Repository\WatchRepository;
use Catenvis\SeriesService;
use Catenvis\TmdbClient;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

// Only one worker at a time.
$lockPath = sys_get_temp_dir() . '/catenvis_import.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
	// A worker is already running - it will pick up new entries too.
	exit(0);
}
@chmod($lockPath, 0666);

$config = Config::load($projectDir . '/config/config.php');
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

$processed = 0;
while (($item = $queue->nextPending()) !== null) {
	$id     = (int) $item['id'];
	$userId = (int) $item['user_id'];
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
		$queue->setResult($id, 'failed', $e->getMessage());
	}

	// Ease the TMDB rate limit.
	usleep(200000);
}

flock($lock, LOCK_UN);
fclose($lock);

fwrite(STDOUT, sprintf("[%s] Import worker finished. Newly imported: %d.\n", date('Y-m-d H:i:s'), $processed));
exit(0);
