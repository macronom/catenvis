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

	/** SQL expression for the worker lock name (at most 64 characters). */
	private const LOCK_NAME_SQL = "CONCAT('catenvis_import:', LEFT(DATABASE(), 48))";

	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Replaces a user's queue (results of the previous import included) with
	 * a new batch, atomically: a failure leaves the old state, never a
	 * half-filled queue the worker would start on.
	 *
	 * @param array<string, string> $items    Map imdbId => title.
	 * @param bool                   $markSeen Whether completed seasons should be marked as watched.
	 */
	public function replaceForUser(int $userId, array $items, bool $markSeen = false): void {
		$this->db->transaction(function () use ($userId, $items, $markSeen): void {
			$this->clear($userId);
			$this->enqueue($userId, $items, $markSeen);
		});
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
	 * Marks an entry as being processed, but only while it is still pending.
	 *
	 * @return bool False if the entry was taken (or cancelled) meanwhile.
	 */
	public function markProcessing(int $id): bool {
		return $this->db->execute(
			"UPDATE import_queue SET status = 'processing' WHERE id = ? AND status = 'pending'",
			[$id]
		) === 1;
	}

	/**
	 * Takes the worker lock on this connection without waiting. It lives in
	 * the database, because the queue does: workers on different hosts that
	 * share the database exclude each other too. The name includes the
	 * database, since GET_LOCK names are global to the database server.
	 * The lock ends with the connection at the latest.
	 *
	 * @return bool True if this connection now holds the lock.
	 */
	public function acquireWorkerLock(): bool {
		return (int) $this->db->fetchValue('SELECT GET_LOCK(' . self::LOCK_NAME_SQL . ', 0)') === 1;
	}

	/**
	 * Releases the worker lock held by this connection.
	 */
	public function releaseWorkerLock(): void {
		$this->db->fetchValue('SELECT RELEASE_LOCK(' . self::LOCK_NAME_SQL . ')');
	}

	/**
	 * Checks whether no worker currently holds the lock.
	 */
	public function isWorkerLockFree(): bool {
		return (int) $this->db->fetchValue('SELECT IS_FREE_LOCK(' . self::LOCK_NAME_SQL . ')') === 1;
	}

	/**
	 * Removes a user's entries that have not been started yet.
	 *
	 * @return int Number of removed entries.
	 */
	public function cancelPending(int $userId): int {
		return $this->db->execute("DELETE FROM import_queue WHERE user_id = ? AND status = 'pending'", [$userId]);
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
