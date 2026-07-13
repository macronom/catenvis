<?php

declare(strict_types=1);

namespace Catenvis\Repository;

use Catenvis\Database;

/**
 * Database access to the users table.
 */
final class UserRepository {
	/** Columns that may be changed as a display setting (whitelist). */
	private const PREFERENCE_COLUMNS = ['pref_sort', 'pref_view', 'pref_titlelang', 'pref_lang'];

	private Database $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findById(int $id): ?array {
		return $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByUsername(string $username): ?array {
		return $this->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		return $this->db->fetchAll('SELECT * FROM users ORDER BY username');
	}

	/**
	 * All active series content languages: the preferences of all users,
	 * merged with the base fetch language and 'en' (the fallback tier,
	 * always synchronized because it comes at no extra API cost).
	 *
	 * @param string $baseLang ISO code of the TMDB base request language.
	 * @return list<string>
	 */
	public function activeContentLanguages(string $baseLang): array {
		$rows  = $this->db->fetchAll('SELECT DISTINCT pref_lang FROM users');
		$langs = array_map(static fn(array $r): string => (string) $r['pref_lang'], $rows);

		return array_values(array_unique(array_merge([$baseLang, 'en'], $langs)));
	}

	/**
	 * All active episode title languages: preferences of non-admin users plus
	 * the base fetch language (its season fetch always yields the base rows).
	 *
	 * Unlike series titles there is no 'en' base tier, because every extra
	 * language costs one season request per season. To retrofit an English
	 * fallback later, simply merge 'en' into this list - the due-check will
	 * backfill all series automatically.
	 *
	 * @param string $baseLang ISO code of the TMDB base request language.
	 * @return list<string>
	 */
	public function activeEpisodeLanguages(string $baseLang): array {
		$rows  = $this->db->fetchAll('SELECT DISTINCT pref_lang FROM users WHERE is_admin = 0');
		$langs = array_map(static fn(array $r): string => (string) $r['pref_lang'], $rows);

		return array_values(array_unique(array_merge([$baseLang], $langs)));
	}

	/**
	 * Creates a new user and returns their ID.
	 */
	public function create(string $username, string $passwordHash, bool $isAdmin): int {
		$this->db->execute(
			'INSERT INTO users (username, password_hash, is_admin, must_change_password)
			 VALUES (?, ?, ?, 1)',
			[$username, $passwordHash, $isAdmin ? 1 : 0]
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Sets a new password and removes the forced change.
	 */
	public function updatePassword(int $userId, string $passwordHash): void {
		$this->db->execute(
			'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
			[$passwordHash, $userId]
		);
	}

	/**
	 * Saves a display setting of the user (column checked via whitelist).
	 */
	public function updatePreference(int $userId, string $column, string $value): void {
		if (!in_array($column, self::PREFERENCE_COLUMNS, true)) {
			throw new \InvalidArgumentException('Unknown settings column: ' . $column);
		}
		$this->db->execute("UPDATE users SET $column = ? WHERE id = ?", [$value, $userId]);
	}

	/**
	 * Updates the timestamp of the last login.
	 */
	public function touchLogin(int $userId): void {
		$this->db->execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
	}

	public function countAll(): int {
		return (int) $this->db->fetchValue('SELECT COUNT(*) FROM users');
	}
}
