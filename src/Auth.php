<?php

declare(strict_types=1);

namespace Catenvis;

use Catenvis\Repository\UserRepository;

/**
 * Authentication and session management of the logged-in user.
 */
final class Auth {
	private const SESSION_KEY = 'user_id';
	private const SESSION_PW_TOKEN = 'pw_token';

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
	 * Verifies the credentials and logs the user in on success.
	 *
	 * @param string|null $initialLang Language preference to store on the very
	 *                                 first login (e.g. negotiated from the
	 *                                 browser); ignored on later logins.
	 */
	public function attempt(string $username, string $password, ?string $initialLang = null): bool {
		$user = $this->users->findByUsername($username);
		if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
			return false;
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
	 * Logs the current user out.
	 */
	public function logout(): void {
		$this->session->remove(self::SESSION_KEY);
		$this->session->remove(self::SESSION_PW_TOKEN);
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
	public function verifyPassword(string $password): bool {
		$user = $this->user();

		return $user !== null && password_verify($password, (string) $user['password_hash']);
	}

	/**
	 * Changes the current user's password, clears the forced-change flag and
	 * logs them out of all sessions (including the current one).
	 */
	public function changePassword(string $newPassword): void {
		$id = $this->userId();
		if ($id === null) {
			return;
		}
		$this->users->updatePassword($id, password_hash($newPassword, PASSWORD_DEFAULT));
		// Other sessions become invalid via the password token,
		// the current one is logged out directly.
		$this->logout();
	}
}
