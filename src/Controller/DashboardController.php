<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;
use Catenvis\SearchQuery;

/**
 * Home page: overview of the followed series incl. progress and new episodes.
 */
final class DashboardController {
	/** Number of followed series per page (the rest is loaded via "Load more"). */
	private const PAGE_SIZE = 36;

	/** Stopped series shown initially and per "Load more" page (secondary, so fewer). */
	private const STOPPED_PAGE_SIZE = 12;

	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	public function index(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();

		// View/sorting: per-user stored preference, otherwise the default.
		$sort = $this->app->preference('pref_sort', ['default', 'name'], 'default');
		$view = $this->app->preference('pref_view', ['grid', 'list'], 'grid');

		$this->app->view->render('dashboard', [
			'pageTitle'      => $this->app->t('My series'),
			'sort'           => $sort,
			'view'           => $view,
			'following'      => $this->app->watch->seriesPage($userId, $sort, 'following', self::PAGE_SIZE, 0, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang),
			'followingTotal' => $this->app->watch->countByFollowStatus($userId, 'following'),
			'stopped'        => $this->app->watch->seriesPage($userId, $sort, 'stopped', self::STOPPED_PAGE_SIZE, 0, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang),
			'stoppedTotal'   => $this->app->watch->countByFollowStatus($userId, 'stopped'),
			'unavailable'    => $this->app->series->unavailableForUser($userId, $this->app->contentLang),
			'pageSize'       => self::PAGE_SIZE,
		]);
	}

	/**
	 * Search within the user's own series (all follow statuses). Shows every
	 * match, grouped like the dashboard; also a single match stays on this page.
	 */
	public function search(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();
		$query  = mb_substr($request->getString('q'), 0, SearchQuery::MAX_LENGTH);
		$terms  = SearchQuery::terms($query);

		$following = [];
		$stopped   = [];
		if ($terms !== []) {
			$following = $this->app->watch->seriesPage($userId, 'search', 'following', null, 0, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang, $terms);
			$stopped   = $this->app->watch->seriesPage($userId, 'search', 'stopped', null, 0, $this->app->titleLang, $this->app->contentLang, $this->app->baseLang, $terms);
		}

		$this->app->view->render('search', [
			'pageTitle' => $query !== '' ? $this->app->t('Search: %s', $query) : $this->app->t('Search my series'),
			'query'     => $query,
			'searched'  => $terms !== [],
			'view'      => $this->app->preference('pref_view', ['grid', 'list'], 'grid'),
			'following' => $following,
			'stopped'   => $stopped,
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
		$pageLimit = $status === 'stopped' ? self::STOPPED_PAGE_SIZE : self::PAGE_SIZE;
		// "Load all": all remaining series from the offset in a single call.
		$limit = $request->getBool('all') ? max(1, $total) : $pageLimit;
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
}
