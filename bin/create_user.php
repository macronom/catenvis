#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

/**
 * CLI seed: creates a user (e.g. the first admin).
 *
 * Usage:
 *   php bin/create_user.php <username> <password> [--admin]
 * Without arguments the values are prompted interactively.
 *
 * The user must change the password on first login.
 */

use Catenvis\Config;
use Catenvis\Database;
use Catenvis\Repository\UserRepository;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Can only be run from the command line.\n");
	exit(1);
}

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$config = Config::load($projectDir . '/config/config.php');
/** @var array<string, mixed> $dbConfig */
$dbConfig = $config->get('db', []);
$users = new UserRepository(new Database($dbConfig));

$argv = $_SERVER['argv'];
$isAdmin = in_array('--admin', $argv, true);
$positional = array_values(array_filter(
	array_slice($argv, 1),
	static fn(string $a): bool => !str_starts_with($a, '--')
));

$username = $positional[0] ?? prompt('Username: ');
$password = $positional[1] ?? prompt('Default password: ');

if ($username === '' || strlen($password) < 8) {
	fwrite(STDERR, "Username required and password at least 8 characters.\n");
	exit(1);
}
if ($users->findByUsername($username) !== null) {
	fwrite(STDERR, "Username is already taken.\n");
	exit(1);
}

$id = $users->create($username, password_hash($password, PASSWORD_DEFAULT), $isAdmin);
printf("User \"%s\" (id %d, %s) created. Password change required on first login.\n",
	$username, $id, $isAdmin ? 'Admin' : 'User');

/**
 * Reads a line of input from the command line.
 */
function prompt(string $label): string {
	fwrite(STDOUT, $label);
	$line = fgets(STDIN);

	return $line === false ? '' : trim($line);
}
