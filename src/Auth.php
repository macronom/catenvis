<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

use Catenvis\Repository\UserRepository;

/**
 * Authentication and session management of the logged-in user.
 */
final class Auth {
	private const SESSION_KEY = 'user_id';
	private const SESSION_PW_TOKEN = 'pw_token';
	/**
	 * bcrypt options for every stored password hash and for the dummy hash.
	 * Fixed instead of PASSWORD_DEFAULT, whose cost depends on the PHP version.
	 */
	private const HASH_OPTIONS = ['cost' => 12];
	/**
	 * bcrypt hash (same cost as HASH_OPTIONS) of a random, discarded password.
	 * An unknown username is verified against it, so the response takes as
	 * long as for a real account and does not reveal which names exist.
	 */
	private const DUMMY_HASH = '$2y$12$g/WDLPF9EmBGRZlDmXi.ie43qx0flN21eu9MDBQVrmy/9aAiNVZlG';

	private UserRepository $users;
	private Session $session;

	/** Cookie lifetime for regular users in seconds (admins: only until browser close). */
	private int $sessionLifetime;

	/** @var array<string, mixed>|null Cache of the current user. */
	private ?array $currentUser = null;
	private bool $loaded = false;

	/**
	 * @param int $sessionLifetime Cookie lifetime for regular users in seconds.
	 */
	public function __construct(UserRepository $users, Session $session, int $sessionLifetime = 0) {
		$this->users           = $users;
		$this->session         = $session;
		$this->sessionLifetime = $sessionLifetime;
	}

	/**
	 * Hashes a password with the application-wide algorithm and cost.
	 */
	public static function hashPassword(#[\SensitiveParameter] string $password): string {
		return password_hash($password, PASSWORD_BCRYPT, self::HASH_OPTIONS);
	}

	/**
	 * Tells whether a password can be hashed at all: bcrypt rejects null bytes.
	 */
	public static function isHashable(#[\SensitiveParameter] string $password): bool {
		return !str_contains($password, "\0");
	}

	/**
	 * Verifies the credentials and logs the user in on success.
	 *
	 * @param string|null $initialLang Language preference to store on the very
	 *                                 first login (e.g. negotiated from the
	 *                                 browser); ignored on later logins.
	 */
	public function attempt(string $username, #[\SensitiveParameter] string $password, ?string $initialLang = null): bool {
		$user = $this->users->findByUsername($username);
		// password_verify ignores everything from a null byte on, so such a
		// password could match a stored one but never be rehashed.
		if ($user === null || !self::isHashable($password)) {
			password_verify($password, self::DUMMY_HASH);
			return false;
		}
		if (!password_verify($password, (string) $user['password_hash'])) {
			return false;
		}

		// Bring a hash of another cost (e.g. created under an older PHP) to the
		// common cost, so failed logins take the same time for every account
		// and for unknown names. Its other sessions end once, as the session
		// token derives from the hash. Only replaced while the stored hash is
		// still the verified one, so parallel logins cannot overwrite a change.
		if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT, self::HASH_OPTIONS)) {
			$newHash = self::hashPassword($password);
			if ($this->users->replacePasswordHash((int) $user['id'], (string) $user['password_hash'], $newHash)) {
				$user['password_hash'] = $newHash;
			}
		}

		// First login ever: adopt the browser language as the starting
		// preference. Every later login keeps the user's explicit choice.
		if ($initialLang !== null && $user['last_login_at'] === null) {
			$this->users->updatePreference((int) $user['id'], 'pref_lang', $initialLang);
			$user['pref_lang'] = $initialLang;
		}

		// Admins should log in again after every browser close -> cookie without
		// a fixed lifetime. Regular users stay logged in for the configured duration.
		$lifetime = (int) $user['is_admin'] === 1 ? 0 : $this->sessionLifetime;

		$this->session->regenerateWithLifetime($lifetime);
		$this->session->set(self::SESSION_KEY, (int) $user['id']);
		$this->session->set(self::SESSION_PW_TOKEN, self::passwordToken($user));
		$this->users->touchLogin((int) $user['id']);
		$this->currentUser = $user;
		$this->loaded      = true;

		return true;
	}

	/**
	 * Logs the current user out and discards all session data, so nothing
	 * (e.g. pending flash messages) is left for the next user of the browser.
	 */
	public function logout(): void {
		$this->session->clear();
		$this->session->regenerate();
		$this->currentUser = null;
		$this->loaded      = true;
	}

	public function isLoggedIn(): bool {
		return $this->user() !== null;
	}

	public function isAdmin(): bool {
		$user = $this->user();

		return $user !== null && (int) $user['is_admin'] === 1;
	}

	public function mustChangePassword(): bool {
		$user = $this->user();

		return $user !== null && (int) $user['must_change_password'] === 1;
	}

	public function userId(): ?int {
		$user = $this->user();

		return $user === null ? null : (int) $user['id'];
	}

	/**
	 * Returns the currently logged-in user or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function user(): ?array {
		if ($this->loaded) {
			return $this->currentUser;
		}

		$this->loaded = true;
		$id = $this->session->get(self::SESSION_KEY);
		if (is_int($id) || (is_string($id) && ctype_digit($id))) {
			$user  = $this->users->findById((int) $id);
			$token = (string) $this->session->get(self::SESSION_PW_TOKEN, '');
			// The session is only valid for the password it was logged in with -
			// a password change therefore invalidates all other sessions.
			if ($user !== null && hash_equals(self::passwordToken($user), $token)) {
				$this->currentUser = $user;
			} else {
				$this->session->remove(self::SESSION_KEY);
				$this->session->remove(self::SESSION_PW_TOKEN);
				$this->session->regenerate();
			}
		}

		return $this->currentUser;
	}

	/**
	 * Session token derived from the password hash: if the password changes,
	 * the token of existing sessions no longer matches.
	 *
	 * @param array<string, mixed> $user
	 */
	private static function passwordToken(array $user): string {
		return hash('sha256', (string) $user['password_hash']);
	}

	/**
	 * Checks whether the given password matches that of the current user.
	 */
	public function verifyPassword(#[\SensitiveParameter] string $password): bool {
		$user = $this->user();

		return $user !== null
			&& self::isHashable($password)
			&& password_verify($password, (string) $user['password_hash']);
	}

	/**
	 * Changes the current user's password, clears the forced-change flag and
	 * logs them out of all sessions (including the current one).
	 */
	public function changePassword(#[\SensitiveParameter] string $newPassword): void {
		$id = $this->userId();
		if ($id === null) {
			return;
		}
		$this->users->updatePassword($id, self::hashPassword($newPassword));
		// Other sessions become invalid via the password token,
		// the current one is logged out directly.
		$this->logout();
	}
}
