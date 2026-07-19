<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;

/**
 * Records failed login attempts for brute-force protection. Attempts are
 * keyed by IP address and by username so both a single-source flood and a
 * distributed attack on one account are throttled.
 */
final class LoginAttemptRepository {
	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Number of failed attempts from an IP since the given datetime (Y-m-d H:i:s).
	 */
	public function failuresByIp(string $ip, string $since): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?',
			[$ip, $since]
		);
	}

	/**
	 * Number of failed attempts for a username since the given datetime.
	 */
	public function failuresByUsername(string $username, string $since): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at >= ?',
			[$username, $since]
		);
	}

	/**
	 * Records one failed login attempt.
	 */
	public function record(string $ip, string $username): void {
		$this->db->execute(
			'INSERT INTO login_attempts (ip, username) VALUES (?, ?)',
			[$ip, $username]
		);
	}

	/**
	 * Clears all recorded failures for a username (after a successful login).
	 */
	public function clearForUsername(string $username): void {
		$this->db->execute('DELETE FROM login_attempts WHERE username = ?', [$username]);
	}

	/**
	 * Removes attempts older than the given datetime (housekeeping).
	 */
	public function prune(string $before): void {
		$this->db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [$before]);
	}
}
