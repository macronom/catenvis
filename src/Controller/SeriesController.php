<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis\Controller;

use Catenvis\App;
use Catenvis\Request;
use Throwable;

/**
 * Series detail view, search/add and follow status.
 */
final class SeriesController {
	private App $app;

	public function __construct(App $app) {
		$this->app = $app;
	}

	/**
	 * Detail view of a series with seasons/episodes and watched status.
	 *
	 * @param array<string, string> $params
	 */
	public function show(Request $request, array $params): void {
		$this->app->requireUser();
		$userId   = (int) $this->app->auth->userId();
		$seriesId = (int) ($params['id'] ?? 0);

		$series = $this->app->series->find($seriesId, $this->app->contentLang);
		if ($series === null) {
			http_response_code(404);
			$this->app->view->render('error', ['pageTitle' => $this->app->t('Not found'), 'message' => $this->app->t('Series not found.')]);
			return;
		}

		$episodes = $this->app->series->episodesForSeries($seriesId, $this->app->contentLang, $this->app->baseLang);
		$watched  = array_fill_keys($this->app->watch->watchedEpisodeIds($userId, $seriesId), true);
		$follow   = $this->app->series->followStatus($userId, $seriesId);

		// Group episodes by season.
		$seasons = [];
		foreach ($episodes as $episode) {
			$seasons[(int) $episode['season_number']][] = $episode;
		}
		krsort($seasons);

		$this->app->view->render('series', [
			'pageTitle'    => \Catenvis\SeriesTitle::pick($series, $this->app->titleLang, $this->app->contentLang),
			'series'       => $series,
			'seasons'      => $seasons,
			'watched'      => $watched,
			'followStatus' => $follow['status'] ?? null,
			'today'        => date('Y-m-d'),
		]);
	}

	/**
	 * Search page: shows the form and TMDB results.
	 */
	public function search(Request $request): void {
		$this->app->requireUser();
		$query   = $request->getString('q');
		$results = [];
		$error   = null;

		if ($query !== '') {
			try {
				$results = $this->app->tmdb()->search($query, $this->app->contentLang);

				// Add English titles via a second search (display only,
				// hence deliberately fault-tolerant).
				try {
					$titlesEn = [];
					foreach ($this->app->tmdb()->search($query, 'en-US') as $item) {
						$titlesEn[(int) ($item['id'] ?? 0)] = (string) ($item['name'] ?? '');
					}
					foreach ($results as &$item) {
						$item['title_en'] = $titlesEn[(int) ($item['id'] ?? 0)] ?? '';
					}
					unset($item);
				} catch (Throwable) {
					// The search stays usable even without English titles.
				}

				// Most popular hits first — the TMDB search itself has no sorting.
				// Older series are downranked: popularity × 0.95 per year since the start
				// (no penalty without a start date, no bonus for future start years).
				$currentYear = (int) date('Y');
				$score = static function (array $item) use ($currentYear): float {
					$year = (int) substr((string) ($item['first_air_date'] ?? ''), 0, 4);
					$diff = $year > 0 ? max(0, $currentYear - $year) : 0;

					return ((float) ($item['popularity'] ?? 0)) * (0.95 ** $diff);
				};
				usort($results, static fn(array $a, array $b): int => $score($b) <=> $score($a));
			} catch (Throwable $e) {
				$error = $this->app->t('The TMDB search failed: %s', $e->getMessage());
			}
		}

		$this->app->view->render('add', [
			'pageTitle' => $this->app->t('Add series'),
			'query'     => $query,
			'results'   => $results,
			'error'     => $error,
		]);
	}

	/**
	 * Adds a series (loading it from TMDB if needed) and follows it.
	 */
	public function add(Request $request): void {
		$this->app->requireUser();
		$this->app->verifyCsrf($request);
		$userId   = (int) $this->app->auth->userId();
		$seriesId = $request->getInt('series_id');

		if ($seriesId <= 0) {
			$this->app->redirect('/add');
		}

		try {
			if ($this->app->series->find($seriesId) === null) {
				$this->app->seriesService()->sync($seriesId);
			}
			$this->app->series->setFollowStatus($userId, $seriesId, 'following');
			$this->app->session->flash('success', $this->app->t('Series added.'));
			$this->app->redirect('/series/' . $seriesId);
		} catch (Throwable $e) {
			$this->app->session->flash('error', $this->app->t('The series could not be loaded: %s', $e->getMessage()));
			$this->app->redirect('/add');
		}
	}

	/**
	 * Removes a series for the current user (and entirely from the DB if
	 * nobody follows it any more).
	 */
	public function remove(Request $request): void {
		$this->app->requireUser();
		$this->app->verifyCsrf($request);
		$userId   = (int) $this->app->auth->userId();
		$seriesId = $request->getInt('series_id');

		if ($seriesId > 0) {
			$this->app->series->removeForUser($userId, $seriesId);
			$this->app->session->flash('success', $this->app->t('Series removed.'));
		}

		$this->app->redirect('/');
	}

	/**
	 * Sets the follow status (following/stopped/deferred).
	 */
	public function setStatus(Request $request): void {
		$this->app->requireUser();
		$this->app->verifyCsrf($request);
		$userId   = (int) $this->app->auth->userId();
		$seriesId = $request->getInt('series_id');
		$status   = $request->getString('status');

		if ($seriesId > 0 && in_array($status, ['following', 'stopped', 'deferred'], true)) {
			$this->app->series->setFollowStatus($userId, $seriesId, $status);
			$message = match ($status) {
				'following' => $this->app->t('You are following this series again.'),
				'stopped'   => $this->app->t('Series stopped – no more new episodes.'),
				'deferred'  => $this->app->t('Series deferred – it moves towards the end of the overview.'),
			};
			$this->app->session->flash('success', $message);
		}

		$this->app->redirect('/series/' . $seriesId);
	}
}
