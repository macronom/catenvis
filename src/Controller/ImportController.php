<?php

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

		// Discard the old history, enqueue the new batch, kick off the worker.
		$markSeen = $request->getBool('mark_seen');
		$this->app->importQueue->clear($userId);
		$this->app->importQueue->enqueue($userId, $consts, $markSeen);
		$this->spawnWorker();

		$this->app->session->flash('success', $this->app->t(
			'%d series queued. The import runs in the background.',
			count($consts)
		));
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
	 *
	 * @return array<string, string>
	 */
	private function parseImdbCsv(string $path): array {
		$handle = fopen($path, 'r');
		if ($handle === false) {
			return [];
		}

		$header = fgetcsv($handle);
		if ($header === false) {
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
		while (($row = fgetcsv($handle)) !== false) {
			$type  = strtolower(preg_replace('/[^a-zA-Z]/', '', (string) ($row[$typeIdx] ?? '')));
			$const = trim((string) ($row[$constIdx] ?? ''));
			if (!in_array($type, self::SERIES_TYPES, true)) {
				continue;
			}
			if (preg_match('/^tt\d+$/', $const) !== 1) {
				continue;
			}
			$title = $titleIdx !== false ? trim((string) ($row[$titleIdx] ?? '')) : '';
			$result[$const] = $title;
		}
		fclose($handle);

		return $result;
	}
}
