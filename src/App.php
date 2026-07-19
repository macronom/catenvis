<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

namespace Catenvis;

use Catenvis\Repository\ImportQueueRepository;
use Catenvis\Repository\LoginAttemptRepository;
use Catenvis\Repository\SeriesRepository;
use Catenvis\Repository\UserRepository;
use Catenvis\Repository\WatchRepository;

/**
 * Central application container: creates and holds the services and
 * wraps common helpers (auth guard, CSRF, redirect).
 */
final class App {
	public readonly Config $config;
	public readonly Session $session;
	public readonly View $view;
	public readonly Auth $auth;
	public readonly UserRepository $users;
	public readonly SeriesRepository $series;
	public readonly WatchRepository $watch;
	public readonly ImportQueueRepository $importQueue;
	public readonly LoginAttemptRepository $loginAttempts;

	/** Per-request CSP nonce for the inline <script> blocks. */
	public readonly string $cspNonce;

	/** TMDB production status => [English label, CSS class]. */
	private const STATUS_TAGS = [
		'Returning Series' => ['Running', 'running'],
		'In Production'    => ['In production', 'running'],
		'Planned'          => ['Planned', 'running'],
		'Pilot'            => ['Pilot', 'running'],
		'Ended'            => ['Ended', 'ended'],
		'Canceled'         => ['Canceled', 'canceled'],
	];

	/** Base language of all TMDB data (ISO code derived from tmdb.language). */
	public readonly string $baseLang;

	/** Preferred content language of the user (always an ISO code). */
	public string $contentLang = 'en';

	/** Resolved title language: ISO code of the own language or 'original'. */
	public string $titleLang = 'en';

	private readonly Database $db;
	private readonly string $projectDir;
	private ?TmdbClient $tmdb = null;
	private ?SeriesService $seriesService = null;
	private ?Translator $translator = null;
	/** @var array<string, string>|null Cached selectable languages (code => autonym). */
	private ?array $languageList = null;

	public function __construct(string $projectDir) {
		$this->projectDir = rtrim($projectDir, '/');
		$this->config = Config::load($this->projectDir . '/config/config.php');

		$this->configureErrorReporting();

		/** @var array<string, mixed> $dbConfig */
		$dbConfig = $this->config->get('db', []);
		$this->db = new Database($dbConfig);

		$sessionLifetime = (int) $this->config->get('session.lifetime', 2592000);
		$this->session = new Session();
		$this->session->start($sessionLifetime);

		$this->baseLang = TmdbClient::languageCode((string) $this->config->get('tmdb.language', 'en-US'));

		$this->cspNonce = bin2hex(random_bytes(16));
		$this->sendSecurityHeaders();

		$baseUrl = (string) $this->config->get('base_url', '');
		$this->view = new View($this->projectDir . '/templates', $baseUrl, $this->session, $this->projectDir . '/html');

		$this->users  = new UserRepository($this->db);
		$this->series = new SeriesRepository($this->db);
		$this->watch  = new WatchRepository($this->db);
		$this->importQueue = new ImportQueueRepository($this->db);
		$this->loginAttempts = new LoginAttemptRepository($this->db);
		$this->auth   = new Auth($this->users, $this->session, $sessionLifetime);

		$this->view->share('app', $this);
		$this->view->share('auth', $this->auth);
		$this->view->share('currentUser', $this->auth->user());
		$this->view->share('dataStale', $this->auth->isLoggedIn() ? $this->dataStaleDays() : null);
		$this->view->share('cspNonce', $this->cspNonce);
		$this->view->share('imageBase', (string) $this->config->get('tmdb.image_base_url', ''));
		$this->view->share('logoBase', (string) $this->config->get('tmdb.logo_base_url', 'https://image.tmdb.org/t/p/w92'));
		$this->view->share('episodeCode', static fn(int $season, int $episode): string => sprintf('S%02dE%02d', $season, $episode));
		$this->view->share('t', fn(string $text, mixed ...$args): string => $this->t($text, ...$args));
		$this->view->share('seriesStatus', fn(?string $status): ?array => $this->seriesStatusTag($status));
		$this->view->share('shortDate', function (string $date): string {
			$ts = strtotime($date);
			if ($ts === false) {
				return '';
			}
			$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
				'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

			// Month name and word order are both translatable: the pattern
			// '%1$s %2$d' renders 'Jan 16'; the German catalog swaps it to
			// '%2$d. %1$s' -> '16. Jan.'. Resolved lazily via $this->t(),
			// because the translator is created after this closure.
			return $this->t('%1$s %2$d', $this->t($months[(int) date('n', $ts) - 1]), (int) date('j', $ts));
		});
	}

	/**
	 * Translates a UI text into the current content language (English source).
	 * Safe to call before resolveTitleLanguage(): falls back to the source text.
	 */
	public function t(string $text, mixed ...$args): string {
		if ($this->translator === null) {
			return $args === [] ? $text : vsprintf($text, $args);
		}

		return $this->translator->translate($text, ...$args);
	}

	/**
	 * Translated status tag for a TMDB production status: [label, CSS class].
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public function seriesStatusTag(?string $status): ?array {
		$tag = self::STATUS_TAGS[$status ?? ''] ?? null;

		return $tag === null ? null : [$this->t($tag[0]), $tag[1]];
	}

	/**
	 * Returns the TMDB client (created lazily on first use).
	 */
	public function tmdb(): TmdbClient {
		if ($this->tmdb === null) {
			/** @var array<string, mixed> $tmdbConfig */
			$tmdbConfig = $this->config->get('tmdb', []);
			$this->tmdb = new TmdbClient($tmdbConfig);
		}

		return $this->tmdb;
	}

	/**
	 * Returns the SeriesService (created lazily on first use).
	 */
	public function seriesService(): SeriesService {
		if ($this->seriesService === null) {
			$this->seriesService = new SeriesService(
				$this->tmdb(),
				$this->series,
				$this->users->activeContentLanguages($this->baseLang),
				$this->users->activeEpisodeLanguages($this->baseLang)
			);
		}

		return $this->seriesService;
	}

	/**
	 * Selectable content languages (ISO code => autonym): English (the source
	 * language, always available) plus every catalog file in lang/. A catalog
	 * names itself via its "__language__" entry; fallback is the uppercased
	 * code. The base fetch language is always selectable as well.
	 *
	 * @return array<string, string>
	 */
	public function languages(): array {
		if ($this->languageList !== null) {
			return $this->languageList;
		}

		$list = ['en' => 'English'];
		foreach (glob($this->projectDir . '/lang/*.json') ?: [] as $file) {
			$code    = basename($file, '.json');
			$decoded = json_decode((string) file_get_contents($file), true);
			$label   = is_array($decoded) && is_string($decoded['__language__'] ?? null)
				? $decoded['__language__']
				: strtoupper($code);
			$list[$code] = $label;
		}
		$list[$this->baseLang] ??= strtoupper($this->baseLang);

		return $this->languageList = $list;
	}

	/**
	 * Best matching installed language from the Accept-Language header, or
	 * null when nothing matches. Only primary subtags are compared ('fr-CH'
	 * matches 'fr'); q-weights determine the preference order.
	 */
	public function browserLanguage(): ?string {
		$header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
		if ($header === '') {
			return null;
		}

		$candidates = [];
		foreach (explode(',', $header) as $part) {
			$pieces = explode(';', trim($part));
			$code   = strtolower(substr(trim($pieces[0]), 0, 2));
			$q      = 1.0;
			foreach (array_slice($pieces, 1) as $param) {
				if (preg_match('/^\s*q=([0-9.]+)/', $param, $match)) {
					$q = (float) $match[1];
				}
			}
			if (preg_match('/^[a-z]{2}$/', $code) === 1 && $q > ($candidates[$code] ?? 0.0)) {
				$candidates[$code] = $q;
			}
		}
		arsort($candidates);

		$installed = array_keys($this->languages());
		foreach (array_keys($candidates) as $code) {
			if (in_array($code, $installed, true)) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * Absolute path to the project directory (above the webroot).
	 */
	public function projectDir(): string {
		return $this->projectDir;
	}

	// --- Helpers ------------------------------------------------------------------

	/**
	 * Redirects to an internal path and ends execution.
	 */
	public function redirect(string $path): never {
		header('Location: ' . $this->view->url($path));
		exit;
	}

	/**
	 * Ensures a user is logged in; otherwise redirects to the login.
	 */
	public function requireLogin(): void {
		if (!$this->auth->isLoggedIn()) {
			$this->redirect('/login');
		}
	}

	/**
	 * Ensures a regular user (not an admin) is logged in.
	 * Admins only manage users and are redirected to their area.
	 */
	public function requireUser(): void {
		$this->requireLogin();
		if ($this->auth->isAdmin()) {
			$this->redirect('/admin/users');
		}
	}

	/**
	 * Whole days the cached TMDB data is behind, or null when it is still
	 * fresh. Shared with the layout to drive the global stale-data banner.
	 * Derived from the newest successful sync (no dedicated heartbeat needed).
	 */
	private function dataStaleDays(): ?int {
		$threshold = (int) $this->config->get('update.stale_after_days', 3);

		return DataFreshness::staleDays($this->series->lastSyncAt(), date('Y-m-d H:i:s'), $threshold);
	}

	/**
	 * Ensures admin privileges.
	 */
	public function requireAdmin(): void {
		$this->requireLogin();
		if (!$this->auth->isAdmin()) {
			http_response_code(403);
			$this->view->render('error', ['pageTitle' => $this->t('Access denied'), 'message' => $this->t('Access denied.')]);
			exit;
		}
	}

	/**
	 * Returns the CSRF token of the current session (creating it if needed).
	 */
	public function csrfToken(): string {
		$token = $this->session->get('_csrf');
		if (!is_string($token)) {
			$token = bin2hex(random_bytes(32));
			$this->session->set('_csrf', $token);
		}

		return $token;
	}

	/**
	 * Verifies the CSRF token of a POST request; aborts on failure.
	 */
	public function verifyCsrf(Request $request): void {
		$token = $this->session->get('_csrf');
		if (!is_string($token) || !hash_equals($token, $request->getString('_csrf'))) {
			http_response_code(400);
			exit($this->t('Invalid CSRF token.'));
		}
	}

	/**
	 * Resolves a display preference: an explicit (valid) request value wins
	 * and is stored per user in the database, otherwise the stored value
	 * applies, otherwise the default. Without login the default always applies.
	 *
	 * @param list<string> $allowed Allowed values (whitelist).
	 */
	public function preference(Request $request, string $param, array $allowed, string $default, string $column): string {
		$userId = $this->auth->userId();
		if ($userId === null) {
			return $default;
		}

		$value = $request->getString($param);
		if (in_array($value, $allowed, true)) {
			$this->users->updatePreference($userId, $column, $value);
			return $value;
		}

		$stored = (string) ($this->auth->user()[$column] ?? '');
		if (in_array($stored, $allowed, true)) {
			return $stored;
		}

		return $default;
	}

	/**
	 * Resolves content language and title mode from request/user setting and
	 * exposes them to the templates ($contentLang, $titleLang, helper $title(row)).
	 * Legacy values pref_titlelang='de' fall back to 'own' via the whitelist.
	 */
	public function resolveTitleLanguage(Request $request): void {
		// Logged out (login page, forced password change) the browser language
		// applies when a matching catalog is installed, otherwise the base
		// language of the installation; users get their stored preference.
		$default = $this->auth->isLoggedIn()
			? $this->baseLang
			: ($this->browserLanguage() ?? $this->baseLang);
		$this->contentLang = $this->preference($request, 'lang', array_keys($this->languages()), $default, 'pref_lang');
		$mode = $this->preference($request, 'titlelang', ['own', 'original'], 'own', 'pref_titlelang');
		$this->titleLang = $mode === 'original' ? 'original' : $this->contentLang;
		$this->translator = new Translator($this->projectDir, $this->contentLang);

		$titleLang   = $this->titleLang;
		$contentLang = $this->contentLang;
		$this->view->share('titleLang', $titleLang);
		$this->view->share('contentLang', $contentLang);
		$this->view->share('title', static fn(array $row): string => SeriesTitle::pick($row, $titleLang, $contentLang));
	}

	/**
	 * Sends the HTTP security headers for every web response. Skipped on the
	 * CLI (cron/bin scripts) and once output has already started.
	 *
	 * The CSP allows only same-origin resources plus TMDB poster/logo images
	 * (image.tmdb.org). Inline <script> blocks are whitelisted per request via
	 * the nonce; inline style attributes (dynamic progress-bar widths) require
	 * 'unsafe-inline' in style-src. HSTS is sent only over HTTPS, because the
	 * sample vhost is HTTP-only (see deploy/SETUP.md).
	 */
	private function sendSecurityHeaders(): void {
		if (PHP_SAPI === 'cli' || headers_sent()) {
			return;
		}

		$csp = "default-src 'self'; "
			. "base-uri 'self'; "
			. "object-src 'none'; "
			. "frame-ancestors 'none'; "
			. "form-action 'self'; "
			. "img-src 'self' https://image.tmdb.org; "
			. "script-src 'self' 'nonce-" . $this->cspNonce . "'; "
			. "style-src 'self' 'unsafe-inline'";

		header('Content-Security-Policy: ' . $csp);
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: DENY');
		header('Referrer-Policy: strict-origin-when-cross-origin');

		$https = (string) ($_SERVER['HTTPS'] ?? '');
		if ($https !== '' && $https !== 'off') {
			header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
		}
	}

	private function configureErrorReporting(): void {
		if ($this->config->isProduction()) {
			error_reporting(E_ALL);
			ini_set('display_errors', '0');
		} else {
			error_reporting(E_ALL);
			ini_set('display_errors', '1');
		}
	}
}
