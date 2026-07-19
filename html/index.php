<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

declare(strict_types=1);

use Catenvis\App;
use Catenvis\Controller\AdminController;
use Catenvis\Controller\DashboardController;
use Catenvis\Controller\ImportController;
use Catenvis\Controller\LoginController;
use Catenvis\Controller\SeriesController;
use Catenvis\Controller\SettingsController;
use Catenvis\Controller\WatchController;
use Catenvis\Request;
use Catenvis\Router;

$projectDir = dirname(__DIR__);
require $projectDir . '/vendor/autoload.php';

$app = new App($projectDir);

$request  = Request::fromGlobals((string) $app->config->get('base_url', ''));
$app->resolveTitleLanguage($request);
$login    = new LoginController($app);
$dashboard = new DashboardController($app);
$series   = new SeriesController($app);
$watch    = new WatchController($app);
$admin    = new AdminController($app);
$import   = new ImportController($app);
$settings = new SettingsController($app);

$router = new Router();

// Public routes (login).
$router->add('GET',  '/login',  [$login, 'showLogin']);
$router->add('POST', '/login',  [$login, 'login']);
$router->add('GET',  '/logout', [$login, 'logout']);

// Password change.
$router->add('GET',  '/change-password', [$login, 'showChangePassword']);
$router->add('POST', '/change-password', [$login, 'changePassword']);

// Personal area (settings).
$router->add('GET', '/settings', [$settings, 'show']);

// Series.
$router->add('GET',  '/',            [$dashboard, 'index']);
$router->add('GET',  '/more',        [$dashboard, 'more']);
$router->add('GET',  '/add',         [$series, 'search']);
$router->add('POST', '/add',         [$series, 'add']);
$router->add('GET',  '/series/{id}', [$series, 'show']);
$router->add('POST', '/follow',      [$series, 'setStatus']);
$router->add('POST', '/remove',      [$series, 'remove']);
$router->add('POST', '/watch',       [$watch, 'toggle']);
$router->add('GET',  '/import',        [$import, 'show']);
$router->add('POST', '/import',        [$import, 'handle']);
$router->add('GET',  '/import/status', [$import, 'status']);

// Admin.
$router->add('GET',  '/admin/users', [$admin, 'users']);
$router->add('POST', '/admin/users', [$admin, 'createUser']);

// Forced password change: redirect everything except the change/logout routes.
$allowedWhilePasswordChange = ['/change-password', '/logout'];
if ($app->auth->isLoggedIn()
	&& $app->auth->mustChangePassword()
	&& !in_array($request->path(), $allowedWhilePasswordChange, true)
) {
	$app->redirect('/change-password');
}

if (!$router->dispatch($request)) {
	http_response_code(404);
	$app->view->render('error', ['pageTitle' => $app->t('Not found'), 'message' => $app->t('Page not found.')]);
}
