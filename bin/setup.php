#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Guided first-time installation: writes config/config.php, loads the schema
 * and creates the first admin account.
 *
 * Prerequisites (need MySQL privileges this script does not require):
 *   - an empty database and a MySQL user for it already exist
 *     (see the header of sql/schema.sql).
 *
 * Usage:
 *   php bin/setup.php            # interactive
 *   php bin/setup.php --force    # overwrite an existing config / non-empty DB
 */

use Catenvis\ConfigWriter;
use Catenvis\Database;
use Catenvis\Migrator;
use Catenvis\Repository\UserRepository;
use Catenvis\TmdbClient;
use Catenvis\TmdbException;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$argv       = $_SERVER['argv'];
$force      = in_array('--force', $argv, true);
$configPath = $projectDir . '/config/config.php';

// 1. Prerequisites.
$missing = [];
if (PHP_VERSION_ID < 80300) {
	$missing[] = 'PHP 8.3+ (found ' . PHP_VERSION . ')';
}
foreach (['pdo_mysql', 'curl'] as $ext) {
	if (!extension_loaded($ext)) {
		$missing[] = "the $ext extension";
	}
}
if ($missing !== []) {
	fwrite(STDERR, 'Missing requirements: ' . implode(', ', $missing) . ".\n");
	exit(1);
}

// 2. Never clobber an existing configuration unless forced.
if (is_file($configPath) && !$force) {
	fwrite(STDERR, "config/config.php already exists. Refusing to overwrite (use --force).\n");
	exit(1);
}

echo "Catenvis setup\n";
echo "==============\n\n";
echo "Creates config/config.php, loads the schema and creates the first admin.\n";
echo "The database and its MySQL user must already exist (see sql/schema.sql).\n\n";

// 3. Collect the database connection and connect (re-prompting on failure).
$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'catenvis';
$dbUser = 'catenvis';
$dbPass = '';
$db     = null;
while ($db === null) {
	$dbHost = promptDefault('DB host', $dbHost);
	$dbPort = promptDefault('DB port', $dbPort);
	$dbName = promptDefault('DB name', $dbName);
	$dbUser = promptDefault('DB user', $dbUser);
	$dbPass = promptHidden('DB password: ');

	$dbConfig = [
		'host'     => $dbHost,
		'port'     => (int) $dbPort,
		'database' => $dbName,
		'user'     => $dbUser,
		'password' => $dbPass,
		'charset'  => 'utf8mb4',
	];
	try {
		$db = new Database($dbConfig);
	} catch (\Throwable $e) {
		fwrite(STDERR, 'Connection failed: ' . $e->getMessage() . "\n\n");
		if (strtolower(promptDefault('Try again? (y/n)', 'y')) !== 'y') {
			exit(1);
		}
	}
}

// 4. Refuse a database that already holds an installation.
$hasUsers = (int) $db->fetchValue(
	"SELECT COUNT(*) FROM information_schema.tables
	 WHERE table_schema = DATABASE() AND table_name = 'users'"
);
if ($hasUsers > 0 && !$force) {
	fwrite(STDERR, "The database already contains a 'users' table - looks like an existing install. Aborting (use --force to proceed anyway).\n");
	exit(1);
}

// 5. Remaining settings.
$environment = promptDefault('Environment (production/development)', 'production');
$baseUrl     = promptDefault('Base URL path (e.g. /catenvis, empty for domain root)', '');
$tmdbToken   = promptHidden('TMDB v4 read access token (leave empty to use a v3 key): ');
$tmdbKey     = $tmdbToken === '' ? promptHidden('TMDB v3 API key: ') : '';
$language    = promptDefault('TMDB base language (BCP 47)', 'en-US');

if ($tmdbToken === '' && $tmdbKey === '') {
	fwrite(STDERR, "A TMDB v4 token or v3 API key is required.\n");
	exit(1);
}

// 6. Optional TMDB connectivity check (a bad token is worth catching now).
$tmdbConfig = [
	'read_access_token' => $tmdbToken,
	'api_key'           => $tmdbKey,
	'language'          => $language,
	'image_base_url'    => 'https://image.tmdb.org/t/p/w342',
	'logo_base_url'     => 'https://image.tmdb.org/t/p/w92',
];
try {
	(new TmdbClient($tmdbConfig))->search('breaking bad');
	echo "TMDB access OK.\n";
} catch (TmdbException $e) {
	if (in_array($e->statusCode(), [401, 403], true)) {
		fwrite(STDERR, "Warning: TMDB rejected the token/key (HTTP {$e->statusCode()}). Fix it in config/config.php later.\n");
	} else {
		echo "TMDB reachable (HTTP {$e->statusCode()}).\n";
	}
} catch (\Throwable $e) {
	fwrite(STDERR, 'Warning: TMDB not reachable (' . $e->getMessage() . "). Check the token/key later.\n");
}

// 7. Write the configuration.
$config = [
	'environment' => $environment,
	'base_url'    => $baseUrl,
	'php_binary'  => PHP_BINARY,
	'session'     => ['lifetime' => 2592000],
	'db'          => [
		'host'     => $dbHost,
		'port'     => (int) $dbPort,
		'database' => $dbName,
		'user'     => $dbUser,
		'password' => $dbPass,
		'charset'  => 'utf8mb4',
	],
	'tmdb'        => $tmdbConfig,
	'update'      => ['stale_after_days' => 3],
];
if (file_put_contents($configPath, ConfigWriter::render($config)) === false) {
	fwrite(STDERR, "Could not write $configPath.\n");
	exit(1);
}
echo "Wrote config/config.php\n";

// 8. Load the full schema (fresh install; includes the migration markers).
$schema = file_get_contents($projectDir . '/sql/schema.sql');
if ($schema === false) {
	fwrite(STDERR, "Cannot read sql/schema.sql.\n");
	exit(1);
}
$statements = Migrator::splitStatements($schema);
foreach ($statements as $statement) {
	$db->execute($statement);
}
printf("Schema loaded (%d statements).\n", count($statements));

// 9. Create the first admin account.
$users = new UserRepository($db);
echo "\nFirst admin account:\n";
$adminUser = promptDefault('Admin username', 'admin');
$adminPass = promptHidden('Admin password (at least 8 characters): ');
if (strlen($adminPass) < 8) {
	fwrite(STDERR, "Password too short - re-run and choose at least 8 characters. (Config and schema are already in place.)\n");
	exit(1);
}
if ($users->findByUsername($adminUser) !== null) {
	fwrite(STDERR, "Username \"$adminUser\" already exists.\n");
	exit(1);
}
$users->create($adminUser, password_hash($adminPass, PASSWORD_DEFAULT), true);
echo "Admin \"$adminUser\" created (password change required on first login).\n";

// 10. Next steps.
echo "\nSetup complete. Next:\n";
echo "  - Point your web server at html/ (see deploy/SETUP.md).\n";
echo "  - Install the update cron (deploy/catenvis.cron).\n";
echo "  - Open the site and log in as \"$adminUser\" to set a new password.\n";

/**
 * Prompts with a default; an empty answer keeps the default.
 */
function promptDefault(string $label, string $default): string {
	$suffix = $default === '' ? '' : " [$default]";
	fwrite(STDOUT, "$label$suffix: ");
	$line = fgets(STDIN);
	$value = $line === false ? '' : trim($line);

	return $value === '' ? $default : $value;
}

/**
 * Prompts without echoing the input (for passwords/tokens). Falls back to a
 * visible prompt when STDIN is not an interactive terminal.
 */
function promptHidden(string $label): string {
	fwrite(STDOUT, $label);
	$tty = function_exists('posix_isatty') && posix_isatty(STDIN);
	if ($tty) {
		shell_exec('stty -echo');
	}
	$line = fgets(STDIN);
	if ($tty) {
		shell_exec('stty echo');
		fwrite(STDOUT, "\n");
	}

	return $line === false ? '' : trim($line);
}
