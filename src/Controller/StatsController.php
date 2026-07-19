<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;

/**
 * Per-user statistics: collection state, viewing totals and weekly activity.
 */
final class StatsController {
	/** Weeks shown in the activity chart. */
	private const ACTIVITY_WEEKS = 8;

	/** Series listed in the "top by time" section. */
	private const TOP_SERIES = 5;

	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	public function index(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();
		$stats  = $this->app->stats;

		// One extra row reveals whether a "show more" button is needed.
		$top     = $stats->topSeriesByTime($userId, $this->app->contentLang, self::TOP_SERIES + 1);
		$hasMore = count($top) > self::TOP_SERIES;

		$this->app->view->render('stats', [
			'pageTitle'    => $this->app->t('Statistics'),
			'collection'   => $stats->collection($userId),
			'watch'        => $stats->watchTotals($userId),
			'backlog'      => $stats->backlog($userId),
			'completed'    => $stats->completedSeriesCount($userId),
			'top'          => array_slice($top, 0, self::TOP_SERIES),
			'topHasMore'   => $hasMore,
			'weeks'        => $stats->weeklyActivity($userId, self::ACTIVITY_WEEKS),
			'activityWeeks' => self::ACTIVITY_WEEKS,
		]);
	}

	/**
	 * JSON fragment: the next page of "top series by time" for the load-more
	 * button (mirrors DashboardController::more). $max keeps the bar widths
	 * scaled consistently against the overall top entry.
	 */
	public function topMore(Request $request): void {
		$this->app->requireUser();
		$userId = (int) $this->app->auth->userId();
		$offset = max(0, $request->getInt('offset'));
		$max    = max(1, $request->getInt('max'));

		$rows    = $this->app->stats->topSeriesByTime($userId, $this->app->contentLang, self::TOP_SERIES + 1, $offset);
		$hasMore = count($rows) > self::TOP_SERIES;
		$rows    = array_slice($rows, 0, self::TOP_SERIES);

		$html = '';
		$rank = $offset;
		foreach ($rows as $row) {
			$rank++;
			$html .= $this->app->view->capture('_partials/stat_top_row', ['row' => $row, 'rank' => $rank, 'max' => $max]);
		}

		header('Content-Type: application/json');
		echo (string) json_encode([
			'html'       => $html,
			'nextOffset' => $offset + count($rows),
			'hasMore'    => $hasMore,
		]);
		exit;
	}
}
