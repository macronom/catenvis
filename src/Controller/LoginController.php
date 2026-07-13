<?php

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

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
		$ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

		// Brute-force protection: block once too many recent failures are
		// recorded for this IP or this username (rolling window, self-releasing).
		$attempts = $this->app->loginAttempts;
		$since    = date('Y-m-d H:i:s', time() - self::LOCKOUT_WINDOW);
		$attempts->prune($since);
		if ($attempts->failuresByIp($ip, $since) >= self::MAX_FAILURES
			|| $attempts->failuresByUsername($username, $since) >= self::MAX_FAILURES) {
			$this->app->session->flash('error', $this->app->t('Too many failed attempts. Please try again later.'));
			$this->app->redirect('/login');
		}

		if ($this->app->auth->attempt($username, $password, $this->app->browserLanguage())) {
			$attempts->clearForUsername($username);
			$this->app->redirect('/');
		}

		$attempts->record($ip, $username);
		$this->app->session->flash('error', $this->app->t('Username or password is incorrect.'));
		$this->app->redirect('/login');
	}

	/**
	 * Meldet den User ab.
	 */
	public function logout(Request $request): void {
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

		if (!$this->app->auth->verifyPassword($current)) {
			$this->app->session->flash('error', $this->app->t('The current password is incorrect.'));
			$this->app->redirect('/change-password');
		}
		if (strlen($new) < 8) {
			$this->app->session->flash('error', $this->app->t('The password must be at least 8 characters long.'));
			$this->app->redirect('/change-password');
		}
		if ($new !== $confirm) {
			$this->app->session->flash('error', $this->app->t('The passwords do not match.'));
			$this->app->redirect('/change-password');
		}

		$this->app->auth->changePassword($new);
		$this->app->session->flash('success', $this->app->t('Password changed. Please log in with your new password.'));
		$this->app->redirect('/login');
	}
}
