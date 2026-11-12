<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

/**
 * Personal area: display settings and access to the password change.
 */
final class SettingsController {
	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	/**
	 * Shows the settings page; each option is a small POST form (save()).
	 * Language and title mode are resolved globally in App::resolveTitleLanguage.
	 */
	public function show(Request $request): void {
		$this->app->requireLogin();

		$sort = $this->app->preference('pref_sort', ['default', 'name'], 'default');
		$view = $this->app->preference('pref_view', ['grid', 'list'], 'grid');

		$this->app->view->render('settings', [
			'pageTitle' => $this->app->t('Settings'),
			'sort'      => $sort,
			'view'      => $view,
			'languages' => $this->app->languages(),
		]);
	}

	/**
	 * Saves the submitted display preferences (whitelisted per field; unknown
	 * or invalid values are ignored) and returns to the settings page.
	 */
	public function save(Request $request): void {
		$this->app->requireLogin();
		$this->app->verifyCsrf($request);
		$userId = (int) $this->app->auth->userId();

		$options = [
			'sort'      => ['pref_sort', ['default', 'name']],
			'view'      => ['pref_view', ['grid', 'list']],
			'lang'      => ['pref_lang', array_keys($this->app->languages())],
			'titlelang' => ['pref_titlelang', ['own', 'original']],
		];
		foreach ($options as $field => [$column, $allowed]) {
			$value = $request->bodyString($field);
			if (in_array($value, $allowed, true)) {
				$this->app->users->updatePreference($userId, $column, $value);
			}
		}

		$this->app->redirect('/settings');
	}
}
