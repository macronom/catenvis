<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Auth;
use Catenvis\Request;
use Catenvis\Username;

/**
 * Login, Logout und erzwungener Passwortwechsel.
 */
final class LoginController {
	/** Max failed attempts per IP or per username within the window. */
	private const MAX_FAILURES = 5;
	/** Rolling lockout window in seconds. */
	private const LOCKOUT_WINDOW = 900;

	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	/**
	 * Zeigt das Login-Formular.
	 */
	public function showLogin(Request $request): void {
		if ($this->app->auth->isLoggedIn()) {
			$this->app->redirect('/');
		}
		$this->app->view->render('login', ['pageTitle' => $this->app->t('Log in')]);
	}

	/**
	 * Verarbeitet den Login.
	 */
	public function login(Request $request): void {
		$this->app->verifyCsrf($request);
		$username = $request->getString('username');
		$password = $request->getString('password');

		// A name that cannot be stored must never reach the lookup: the
		// collation ignores some characters, so it could still match a real
		// account while recording the attempt fails on the column width.
		if (!Username::fits($username)) {
			$this->app->session->flash('error', $this->app->t('Username or password is incorrect.'));
			$this->app->redirect('/login');
		}

		$attemptId = $this->recordAttempt($username, '/login');
		if ($this->app->auth->attempt($username, $password, $this->app->browserLanguage())) {
			$attempts = $this->app->loginAttempts;
			$attempts->remove($attemptId);
			$attempts->clearForUsername($username);
			$this->app->redirect('/');
		}

		$this->app->session->flash('error', $this->app->t('Username or password is incorrect.'));
		$this->app->redirect('/login');
	}

	/**
	 * Meldet den User ab.
	 */
	public function logout(Request $request): void {
		// A session that is no longer logged in (e.g. invalidated by a password
		// change elsewhere, which also renewed its id and CSRF token) has
		// nothing to log out; it needs no token protection.
		if (!$this->app->auth->isLoggedIn()) {
			$this->app->redirect('/login');
		}
		$this->app->verifyCsrf($request);
		$this->app->auth->logout();
		$this->app->redirect('/login');
	}

	/**
	 * Zeigt das Formular zum Passwortwechsel.
	 */
	public function showChangePassword(Request $request): void {
		$this->app->requireLogin();
		$this->app->view->render('change_password', ['pageTitle' => $this->app->t('Change password')]);
	}

	/**
	 * Verarbeitet den Passwortwechsel.
	 */
	public function changePassword(Request $request): void {
		$this->app->requireLogin();
		$this->app->verifyCsrf($request);

		$current = $request->getString('current_password');
		$new     = $request->getString('password');
		$confirm = $request->getString('password_confirm');

		// The current password is guessable here too (e.g. at an unattended
		// browser), so failures count towards the login limits of this account.
		$username  = (string) ($this->app->auth->user()['username'] ?? '');
		$attemptId = $this->recordAttempt($username, '/change-password');
		if (!$this->app->auth->verifyPassword($current)) {
			$this->app->session->flash('error', $this->app->t('The current password is incorrect.'));
			$this->app->redirect('/change-password');
		}
		$this->app->loginAttempts->remove($attemptId);

		if (strlen($new) < 8) {
			$this->app->session->flash('error', $this->app->t('The password must be at least 8 characters long.'));
			$this->app->redirect('/change-password');
		}
		if (!Auth::isHashable($new)) {
			$this->app->session->flash('error', $this->app->t('The password contains an invalid character.'));
			$this->app->redirect('/change-password');
		}
		if ($new !== $confirm) {
			$this->app->session->flash('error', $this->app->t('The passwords do not match.'));
			$this->app->redirect('/change-password');
		}
		if ($new === $current) {
			$this->app->session->flash('error', $this->app->t('The new password must differ from the current one.'));
			$this->app->redirect('/change-password');
		}

		$this->app->auth->changePassword($new);
		$this->app->session->flash('success', $this->app->t('Password changed. Please log in with your new password.'));
		$this->app->redirect('/login');
	}

	/**
	 * Records a password check for the brute-force protection and stops with
	 * an error when the limits are exceeded (rolling window, self-releasing).
	 *
	 * The attempt is recorded before the check and counted including itself,
	 * so parallel requests cannot all pass before the first failure is
	 * stored. A blocked attempt removes its own row again so that hammering a
	 * locked account does not extend the lock-out. The caller removes the row
	 * once the password turned out to be correct.
	 *
	 * @param string $redirect Path to return to when blocked.
	 * @return int Id of the recorded attempt.
	 */
	private function recordAttempt(string $username, string $redirect): int {
		$ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
		$attempts = $this->app->loginAttempts;
		$attempts->prune(self::LOCKOUT_WINDOW);
		$attemptId = $attempts->record($ip, $username);
		if ($attempts->failuresByIp($ip, self::LOCKOUT_WINDOW) > self::MAX_FAILURES
			|| $attempts->failuresByUsername($username, self::LOCKOUT_WINDOW) > self::MAX_FAILURES) {
			$attempts->remove($attemptId);
			$this->app->session->flash('error', $this->app->t('Too many failed attempts. Please try again later.'));
			$this->app->redirect($redirect);
		}

		return $attemptId;
	}
}
