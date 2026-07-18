<?php

declare(strict_types=1);

/**
 * Catenvis configuration.
 *
 * Copy this file to `config/config.php` and fill in real values.
 * `config/config.php` lives outside the webroot and is excluded from
 * the repository via .gitignore.
 *
 * @return array<string, mixed>
 */

return [
	// 'production' hides PHP errors, 'development' shows them.
	'environment' => 'development',

	// Base URL path the application is served from (no trailing slash).
	// Typically '' (domain root); for a subdirectory e.g. '/catenvis'.
	'base_url' => '',

	// Path to the PHP CLI binary. Used to launch the IMDb import in the background.
	'php_binary' => '/usr/bin/php',

	'session' => [
		// Lifetime of the login cookie in seconds. 0 = until the browser is
		// closed; > 0 keeps the login across browser restarts (30 days).
		// Applies to regular users only – admins always have to log in again.
		'lifetime' => 2592000,
	],

	'db' => [
		'host'     => '127.0.0.1',
		'port'     => 3306,
		'database' => 'catenvis',
		'user'     => 'catenvis',
		'password' => 'ENTER_DB_PASSWORD_HERE',
		'charset'  => 'utf8mb4',
	],

	'tmdb' => [
		// v4 read access token (Bearer). Preferred when set.
		'read_access_token' => 'ENTER_TMDB_V4_TOKEN_HERE',
		// Alternatively a v3 API key. Only needed without a v4 token.
		'api_key'           => '',
		// Base language for all TMDB requests (BCP 47). Ideally set this to
		// the language you will mainly use for display later: it delivers
		// the richest data and the episode titles, and the sync stores it
		// under its own ISO-639-1 code (here: 'en'). Series titles in other
		// active user languages come from the translations payload at no
		// extra cost; episode titles cost one additional season request per
		// extra language.
		'language'          => 'en-US',
		// Base URL for poster images (size w342 is a good compromise).
		'image_base_url'    => 'https://image.tmdb.org/t/p/w342',
		// Base URL for network/platform logos (smaller size).
		'logo_base_url'     => 'https://image.tmdb.org/t/p/w92',
	],

	'update' => [
		// Global "stale data" warning. When the newest successful series sync
		// is at least this many days old, a banner tells logged-in users the
		// automatic nightly update may have stopped (dead cron, broken API key,
		// TMDB down). A value below 1 disables the warning.
		'stale_after_days' => 3,
	],

	// Note: the selectable languages are not configured here. English is
	// always available (source language); every other language appears
	// automatically once a catalog file lang/<code>.json exists
	// (see lang/README.md).
];
