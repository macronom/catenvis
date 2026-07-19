<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

/**
 * Home page: overview of the followed series incl. progress and new episodes.
 */
final class DashboardController {
	/** Number of followed series per page (the rest is loaded via "Load more"). */
	private const PAGE_SIZE = 36;

	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	public function index(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();

		// View/sorting: per-user stored preference, otherwise the default.
		$sort = $this->resolvePreference($request, 'sort', ['default', 'name'], 'default', 'pref_sort');
		$view = $this->resolvePreference($request, 'view', ['grid', 'list'], 'grid', 'pref_view');

		$this->app->view->render('dashboard', [
			'pageTitle'      => $this->app->t('My series'),
			'sort'           => $sort,
			'view'           => $view,
			'following'      => $this->app->watch->seriesPage($userId, $sort, 'following', self::PAGE_SIZE, 0, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang),
			'followingTotal' => $this->app->watch->countByFollowStatus($userId, 'following'),
			'stopped'        => $this->app->watch->seriesPage($userId, $sort, 'stopped', self::PAGE_SIZE, 0, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang),
			'stoppedTotal'   => $this->app->watch->countByFollowStatus($userId, 'stopped'),
			'unavailable'    => $this->app->series->unavailableForUser($userId, $this->app->contentLang),
			'pageSize'       => self::PAGE_SIZE,
		]);
	}

	/**
	 * Returns the next page of series as an HTML fragment (for "Load more").
	 */
	public function more(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();
		$sort   = $this->sortMode($request);
		$offset = max(0, $request->getInt('offset'));
		$status = $request->getString('status') === 'stopped' ? 'stopped' : 'following';

		$total = $this->app->watch->countByFollowStatus($userId, $status);
		// "Load all": all remaining series from the offset in a single call.
		$limit = $request->getBool('all') ? max(1, $total) : self::PAGE_SIZE;
		$rows  = $this->app->watch->seriesPage($userId, $sort, $status, $limit, $offset, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang);

		$html = '';
		foreach ($rows as $row) {
			$html .= $this->app->view->capture('_partials/series_card', ['row' => $row]);
		}
		$shown = $offset + count($rows);

		header('Content-Type: application/json');
		echo (string) json_encode([
			'html'       => $html,
			'nextOffset' => $shown,
			'hasMore'    => $shown < $total,
		]);
		exit;
	}

	/**
	 * Determines the sort mode from the request (whitelist).
	 */
	private function sortMode(Request $request): string {
		return $request->getString('sort') === 'name' ? 'name' : 'default';
	}

	/**
	 * Resolves a display preference (delegates to App::preference).
	 *
	 * @param list<string> $allowed Allowed values (whitelist).
	 */
	private function resolvePreference(Request $request, string $param, array $allowed, string $default, string $column): string {
		return $this->app->preference($request, $param, $allowed, $default, $column);
	}
}
