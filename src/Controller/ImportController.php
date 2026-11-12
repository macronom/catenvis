<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

/**
 * Imports followed series from an IMDb CSV export (ratings or watchlist).
 * The matching is done exactly via the IMDb ID through the TMDB /find endpoint.
 * The actual processing runs in the background (bin/process_imports.php).
 */
final class ImportController {
	/**
	 * IMDb title types that are imported as a series – normalized
	 * (lowercased, without non-letters). Covers both the old format
	 * "tvSeries"/"tvMiniSeries" and the new "TV Series"/"TV Mini Series".
	 */
	private const SERIES_TYPES = ['tvseries', 'tvminiseries'];

	/** Maximum number of series accepted from one CSV file. */
	private const MAX_IMPORT_ROWS = 5000;

	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	/**
	 * Shows the upload form and – if present – the state of the queue.
	 */
	public function show(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();

		$counts  = $this->app->importQueue->statusCounts($userId);
		$pending = ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0);

		// Open entries but possibly no worker (e.g. it was killed): start one.
		// A worker that is already running holds the lock; the new one exits.
		if ($pending > 0) {
			$this->spawnWorker();
		}

		$this->app->view->render('import', [
			'pageTitle' => $this->app->t('Import from IMDb'),
			'counts'    => $counts,
			'pending'   => $pending,
			'results'   => $this->app->importQueue->results($userId),
		]);
	}

	/**
	 * Returns the current state of the queue as JSON (for polling).
	 */
	public function status(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();
		$counts = $this->app->importQueue->statusCounts($userId);

		header('Content-Type: application/json');
		echo (string) json_encode([
			'pending' => ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0),
			'counts'  => [
				'done'     => $counts['done'] ?? 0,
				'skipped'  => $counts['skipped'] ?? 0,
				'notfound' => $counts['notfound'] ?? 0,
				'failed'   => $counts['failed'] ?? 0,
			],
		]);
		exit;
	}

	/**
	 * Accepts the CSV upload, fills the queue and starts the worker.
	 */
	public function handle(Request $request): void {
		$this->app->requireUser();
		$this->app->verifyCsrf($request);
		$userId = (int) $this->app->auth->userId();

		if ($this->app->importQueue->hasUnfinished($userId)) {
			$this->app->session->flash('error', $this->app->t('An import is already running. Please wait until it finishes.'));
			$this->app->redirect('/import');
		}

		$file = $_FILES['csv'] ?? null;
		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
			$this->app->session->flash('error', $this->app->t('Please select a CSV file.'));
			$this->app->redirect('/import');
		}

		$consts = $this->parseImdbCsv((string) $file['tmp_name']);
		if ($consts === []) {
			$this->app->session->flash('error', $this->app->t('No series (tvSeries/tvMiniSeries) found in the file.'));
			$this->app->redirect('/import');
		}
		$truncated = count($consts) > self::MAX_IMPORT_ROWS;
		if ($truncated) {
			$consts = array_slice($consts, 0, self::MAX_IMPORT_ROWS, true);
		}

		// Discard the old history, enqueue the new batch, kick off the worker.
		$markSeen = $request->getBool('mark_seen');
		$this->app->importQueue->clear($userId);
		$this->app->importQueue->enqueue($userId, $consts, $markSeen);
		$this->spawnWorker();

		$this->app->session->flash('success', $this->app->t(
			'%d series queued. The import runs in the background.',
			count($consts)
		));
		if ($truncated) {
			$this->app->session->flash('error', $this->app->t(
				'The file contains more series than allowed; only the first %d were queued.',
				self::MAX_IMPORT_ROWS
			));
		}
		$this->app->redirect('/import');
	}

	/**
	 * Starts the background worker as a detached process (if possible).
	 */
	private function spawnWorker(): void {
		if (!function_exists('exec')) {
			return;
		}
		$php    = (string) $this->app->config->get('php_binary', '/usr/bin/php');
		$worker = $this->app->projectDir() . '/bin/process_imports.php';
		$log    = sys_get_temp_dir() . '/catenvis_import.log';

		$cmd = sprintf(
			'nohup %s %s >> %s 2>&1 &',
			escapeshellarg($php),
			escapeshellarg($worker),
			escapeshellarg($log)
		);
		exec($cmd);
	}

	/**
	 * Reads the IMDb CSV and returns a map imdbId => title for series rows.
	 * Stops after one entry more than MAX_IMPORT_ROWS, so the caller can tell
	 * that the file was longer than allowed.
	 *
	 * @return array<string, string>
	 */
	private function parseImdbCsv(string $path): array {
		$handle = fopen($path, 'r');
		if ($handle === false) {
			return [];
		}

		$header = $this->readCsvRow($handle);
		if ($header === null) {
			fclose($handle);
			return [];
		}
		// Strip the BOM from the first header field and normalize columns.
		$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
		$columns = array_map(static fn($h): string => strtolower(trim((string) $h)), $header);
		$constIdx = array_search('const', $columns, true);
		$typeIdx  = array_search('title type', $columns, true);
		$titleIdx = array_search('title', $columns, true);
		if ($constIdx === false || $typeIdx === false) {
			fclose($handle);
			return [];
		}

		$result = [];
		while (($row = $this->readCsvRow($handle)) !== null) {
			$type  = strtolower(preg_replace('/[^a-zA-Z]/', '', (string) ($row[$typeIdx] ?? '')));
			$const = trim((string) ($row[$constIdx] ?? ''));
			if (!in_array($type, self::SERIES_TYPES, true)) {
				continue;
			}
			// Fits import_queue.imdb_id (VARCHAR(20)).
			if (preg_match('/\Att\d{1,12}\z/', $const) !== 1) {
				continue;
			}
			$title = $titleIdx !== false ? trim((string) ($row[$titleIdx] ?? '')) : '';
			// Fits import_queue.title (VARCHAR(255)); invalid UTF-8 yields ''.
			$result[$const] = preg_match('/\A.{0,255}/us', $title, $match) === 1 ? $match[0] : '';
			if (count($result) > self::MAX_IMPORT_ROWS) {
				break;
			}
		}
		fclose($handle);

		return $result;
	}

	/**
	 * Reads the next non-blank CSV row, or null at the end of the file.
	 * Passes all arguments explicitly: the escape character is disabled
	 * (RFC 4180 quoting only, as in IMDb exports); relying on its default
	 * is deprecated as of PHP 8.4.
	 *
	 * @param resource $handle
	 * @return list<string|null>|null
	 */
	private function readCsvRow($handle): ?array {
		while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
			if ($row !== [null]) {
				return $row;
			}
		}

		return null;
	}
}
