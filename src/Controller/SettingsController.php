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
	 * Shows the settings page; clicking an option saves it immediately.
	 * The title language is already handled globally in App::resolveTitleLanguage.
	 */
	public function show(Request $request): void {
		$this->app->requireLogin();

		$sort = $this->app->preference($request, 'sort', ['default', 'name'], 'default', 'pref_sort');
		$view = $this->app->preference($request, 'view', ['grid', 'list'], 'grid', 'pref_view');

		$this->app->view->render('settings', [
			'pageTitle' => $this->app->t('Settings'),
			'sort'      => $sort,
			'view'      => $view,
			'languages' => $this->app->languages(),
		]);
	}
}
