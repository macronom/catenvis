<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

/**
 * Marks episodes, seasons or whole series as watched or unwatched.
 */
final class WatchController {
	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	/**
	 * Processes a watched action and returns to the series or the overview.
	 */
	public function toggle(Request $request): void {
		$this->app->requireUser();
		$this->app->verifyCsrf($request);

		$userId   = (int) $this->app->auth->userId();
		$seriesId = $request->getInt('series_id');
		$scope    = $request->getString('scope');    // episode | season | series
		$action   = $request->getString('action');   // watch | unwatch
		$return   = $request->getString('return');   // '' | 'dashboard'
		$watch    = $action !== 'unwatch';

		switch ($scope) {
			case 'episode':
				$episodeId = $request->getInt('episode_id');
				if ($episodeId > 0) {
					$watch
						? $this->app->watch->markEpisode($userId, $episodeId)
						: $this->app->watch->unmarkEpisode($userId, $episodeId);
				}
				break;

			case 'season':
				$season = $request->getInt('season_number');
				$watch
					? $this->app->watch->markSeason($userId, $seriesId, $season)
					: $this->app->watch->unmarkSeason($userId, $seriesId, $season);
				break;

			case 'series':
				$watch
					? $this->app->watch->markSeries($userId, $seriesId)
					: $this->app->watch->unmarkSeries($userId, $seriesId);
				break;
		}

		// Quick action from the overview: back to the dashboard instead of the detail page.
		// Only the whitelist value 'dashboard' is accepted (no open redirect).
		if ($return === 'dashboard') {
			// Whoever ticks off an episode of a deferred series is obviously
			// following it again – reactivate the status automatically.
			if ($scope === 'episode' && $watch && $seriesId > 0) {
				$follow = $this->app->series->followStatus($userId, $seriesId);
				if (($follow['status'] ?? null) === 'deferred') {
					$this->app->series->setFollowStatus($userId, $seriesId, 'following');
				}
			}
			$this->app->redirect('/');
		}

		$this->app->redirect('/series/' . $seriesId);
	}
}
