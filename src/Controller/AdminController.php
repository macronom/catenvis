<?php

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

/**
 * Admin-Bereich: Benutzerkonten anlegen und verwalten.
 */
final class AdminController {
	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	/**
	 * Zeigt die Benutzerliste und das Anlegeformular.
	 */
	public function users(Request $request): void {
		$this->app->requireAdmin();
		$this->app->view->render('admin_users', [
			'pageTitle' => $this->app->t('User management'),
			'users'     => $this->app->users->all(),
		]);
	}

	/**
	 * Legt einen neuen Benutzer mit Default-Passwort an.
	 */
	public function createUser(Request $request): void {
		$this->app->requireAdmin();
		$this->app->verifyCsrf($request);

		$username = $request->getString('username');
		$password = $request->getString('password');
		$isAdmin  = $request->getBool('is_admin');

		if ($username === '' || strlen($password) < 8) {
			$this->app->session->flash('error', $this->app->t('Username required, password at least 8 characters.'));
			$this->app->redirect('/admin/users');
		}
		if ($this->app->users->findByUsername($username) !== null) {
			$this->app->session->flash('error', $this->app->t('Username is already taken.'));
			$this->app->redirect('/admin/users');
		}

		$this->app->users->create($username, password_hash($password, PASSWORD_DEFAULT), $isAdmin);
		$this->app->session->flash('success', $this->app->t('User "%s" created. The password must be changed on first login.', $username));
		$this->app->redirect('/admin/users');
	}
}
