<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;

/**
 * Records login attempts for brute-force protection. Attempts are
 * keyed by IP address and by username so both a single-source flood and a
 * distributed attack on one account are throttled.
 */
final class LoginAttemptRepository {
	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Number of recorded attempts from an IP within the last $windowSeconds.
	 */
	public function failuresByIp(string $ip, int $windowSeconds): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
			[$ip, $windowSeconds]
		);
	}

	/**
	 * Number of recorded attempts for a username within the last $windowSeconds.
	 */
	public function failuresByUsername(string $username, int $windowSeconds): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
			[$username, $windowSeconds]
		);
	}

	/**
	 * Records one login attempt and returns its row id. The timestamp is set
	 * explicitly in UTC on the database clock, the same clock the window
	 * queries use, so neither PHP's time zone nor daylight-saving changes
	 * affect the window.
	 */
	public function record(string $ip, string $username): int {
		$this->db->execute(
			'INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())',
			[$ip, $username]
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Removes a single recorded attempt (blocked or successful login).
	 */
	public function remove(int $id): void {
		$this->db->execute('DELETE FROM login_attempts WHERE id = ?', [$id]);
	}

	/**
	 * Clears all recorded failures for a username (after a successful login).
	 */
	public function clearForUsername(string $username): void {
		$this->db->execute('DELETE FROM login_attempts WHERE username = ?', [$username]);
	}

	/**
	 * Removes attempts older than $windowSeconds (housekeeping).
	 */
	public function prune(int $windowSeconds): void {
		$this->db->execute(
			'DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL ? SECOND',
			[$windowSeconds]
		);
	}
}
