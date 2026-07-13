<?php

declare(strict_types=1);

namespace Catenvis;

/**
 * Wraps the native PHP session including flash messages.
 */
final class Session {
	/**
	 * Starts the session if not already started.
	 *
	 * @param int $lifetime Lifetime of the session cookie in seconds. 0 means
	 *                      "until the browser is closed"; a value > 0 keeps the
	 *                      login across browser restarts.
	 */
	public function start(int $lifetime = 0): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			return;
		}

		// Keep server-side session data at least as long as the cookie,
		// otherwise the session is cleaned up prematurely despite a valid cookie.
		if ($lifetime > 0) {
			ini_set('session.gc_maxlifetime', (string) $lifetime);
		}

		$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

		session_name('cvsid');
		session_set_cookie_params([
			'lifetime' => $lifetime,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		session_start();
	}

	/**
	 * Renews the session ID and sets a new cookie lifetime in the process.
	 * Used at login to set the lifetime depending on the user role.
	 *
	 * With an active session the cookie parameters can be changed neither via
	 * session_set_cookie_params() nor via ini_set(); therefore the cookie is
	 * set explicitly with the desired expiry after rotating the ID.
	 *
	 * @param int $lifetime New lifetime in seconds (0 = until browser close).
	 */
	public function regenerateWithLifetime(int $lifetime): void {
		session_regenerate_id(true);

		$name = session_name();
		$id   = session_id();
		if ($name === false || $id === false) {
			return;
		}

		$params = session_get_cookie_params();
		setcookie($name, $id, [
			'expires'  => $lifetime > 0 ? time() + $lifetime : 0,
			'path'     => $params['path'],
			'domain'   => $params['domain'],
			'secure'   => $params['secure'],
			'httponly' => $params['httponly'],
			'samesite' => 'Lax',
		]);
	}

	public function get(string $key, mixed $default = null): mixed {
		return $_SESSION[$key] ?? $default;
	}

	public function set(string $key, mixed $value): void {
		$_SESSION[$key] = $value;
	}

	public function has(string $key): bool {
		return isset($_SESSION[$key]);
	}

	public function remove(string $key): void {
		unset($_SESSION[$key]);
	}

	/**
	 * Renews the session ID (against session fixation), keeps the data.
	 */
	public function regenerate(): void {
		session_regenerate_id(true);
	}

	/**
	 * Discards the entire session.
	 */
	public function destroy(): void {
		$_SESSION = [];
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
	}

	/**
	 * Sets a flash message that is delivered once on the next retrieval.
	 *
	 * @param string $type "success" or "error".
	 */
	public function flash(string $type, string $message): void {
		$_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
	}

	/**
	 * Returns and clears all flash messages.
	 *
	 * @return list<array{type: string, message: string}>
	 */
	public function takeFlashes(): array {
		/** @var list<array{type: string, message: string}> $flashes */
		$flashes = $_SESSION['_flash'] ?? [];
		unset($_SESSION['_flash']);

		return $flashes;
	}
}
