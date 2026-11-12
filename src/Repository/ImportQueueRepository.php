<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;

/**
 * Database access to the import queue (import_queue).
 */
final class ImportQueueRepository {
	/** Width of import_queue.message in characters. */
	public const MESSAGE_LENGTH = 255;

	/** Result message (translation key) for entries of an interrupted worker. */
	public const INTERRUPTED = 'The import was interrupted.';

	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Enqueues multiple series into the queue.
	 *
	 * @param array<string, string> $items    Map imdbId => title.
	 * @param bool                   $markSeen Whether completed seasons should be marked as watched.
	 */
	public function enqueue(int $userId, array $items, bool $markSeen = false): void {
		foreach ($items as $imdbId => $title) {
			$this->db->execute(
				'INSERT INTO import_queue (user_id, imdb_id, title, mark_seen) VALUES (?, ?, ?, ?)',
				[$userId, $imdbId, $title !== '' ? $title : null, $markSeen ? 1 : 0]
			);
		}
	}

	/**
	 * Number of a user's entries per status.
	 *
	 * @return array<string, int>
	 */
	public function statusCounts(int $userId): array {
		$rows = $this->db->fetchAll(
			'SELECT status, COUNT(*) AS n FROM import_queue WHERE user_id = ? GROUP BY status',
			[$userId]
		);
		$counts = [];
		foreach ($rows as $row) {
			$counts[(string) $row['status']] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Checks whether unprocessed entries still exist for a user.
	 */
	public function hasUnfinished(int $userId): bool {
		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM import_queue
			 WHERE user_id = ? AND status IN ('pending','processing')",
			[$userId]
		) > 0;
	}

	/**
	 * Entries of a user whose processing has a result (not pending).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function results(int $userId): array {
		return $this->db->fetchAll(
			"SELECT * FROM import_queue
			 WHERE user_id = ? AND status NOT IN ('pending','processing')
			 ORDER BY status, title",
			[$userId]
		);
	}

	/**
	 * Removes all entries of a user (e.g. before a new import).
	 */
	public function clear(int $userId): void {
		$this->db->execute('DELETE FROM import_queue WHERE user_id = ?', [$userId]);
	}

	/**
	 * Fetches the next open entry or null, alternating between users (round
	 * robin), so one large import cannot hold back everybody else's.
	 *
	 * @param int $afterUserId User served last (0 = start with the lowest user id).
	 * @return array<string, mixed>|null
	 */
	public function nextPending(int $afterUserId = 0): ?array {
		$sql = "SELECT * FROM import_queue WHERE status = 'pending' AND user_id > ? ORDER BY user_id, id LIMIT 1";

		return $this->db->fetchOne($sql, [$afterUserId]) ?? $this->db->fetchOne($sql, [0]);
	}

	/**
	 * Marks an entry as being processed.
	 */
	public function markProcessing(int $id): void {
		$this->db->execute("UPDATE import_queue SET status = 'processing' WHERE id = ?", [$id]);
	}

	/**
	 * Fails entries a previous worker left in processing (killed, crashed).
	 * Only call while holding the worker lock.
	 */
	public function failStale(): void {
		$this->db->execute(
			"UPDATE import_queue SET status = 'failed', message = ?, processed_at = NOW() WHERE status = 'processing'",
			[self::INTERRUPTED]
		);
	}

	/**
	 * Cuts a message to the column width; invalid UTF-8 becomes a generic text.
	 */
	public static function fitMessage(string $message): string {
		if (preg_match('/\A.{0,' . self::MESSAGE_LENGTH . '}/us', $message, $match) !== 1) {
			return 'Import failed.';
		}

		return $match[0];
	}

	/**
	 * Sets the final result of an entry.
	 */
	public function setResult(int $id, string $status, ?string $message = null): void {
		$this->db->execute(
			'UPDATE import_queue SET status = ?, message = ?, processed_at = NOW() WHERE id = ?',
			[$status, $message, $id]
		);
	}
}
