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
	 * Fetches the next open entry (across all users) or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function nextPending(): ?array {
		return $this->db->fetchOne(
			"SELECT * FROM import_queue WHERE status = 'pending' ORDER BY id LIMIT 1"
		);
	}

	/**
	 * Marks an entry as being processed.
	 */
	public function markProcessing(int $id): void {
		$this->db->execute("UPDATE import_queue SET status = 'processing' WHERE id = ?", [$id]);
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
