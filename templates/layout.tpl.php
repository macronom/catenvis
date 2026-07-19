<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom
?>
<!DOCTYPE html>
<html lang="<?= $e($contentLang ?? 'en') ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $e($pageTitle ?? 'Catenvis') ?> · Catenvis</title>
	<link rel="stylesheet" href="<?= $e($asset('/assets/css/catenvis.css')) ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="<?= $e($url('/')) ?>">Catenvis</a>
	<?php if ($auth->isLoggedIn() && !$auth->mustChangePassword()): ?>
		<nav class="nav">
			<?php if ($auth->isAdmin()): ?>
				<a href="<?= $e($url('/admin/users')) ?>"><?= $e($t('Users')) ?></a>
			<?php else: ?>
				<a href="<?= $e($url('/')) ?>"><?= $e($t('My series')) ?></a>
				<a href="<?= $e($url('/add')) ?>"><?= $e($t('Add series')) ?></a>
				<a href="<?= $e($url('/stats')) ?>"><?= $e($t('Statistics')) ?></a>
				<a href="<?= $e($url('/import')) ?>"><?= $e($t('Import')) ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
	<?php if ($auth->isLoggedIn()): ?>
		<div class="userbox">
			<a class="user-link" href="<?= $e($url('/settings')) ?>"><?= $e($currentUser['username'] ?? '') ?></a>
			<a class="logout-link" href="<?= $e($url('/logout')) ?>" title="<?= $e($t('Log out')) ?>" aria-label="<?= $e($t('Log out')) ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
			</a>
		</div>
	<?php endif; ?>
</header>

<main class="content">
	<?php if (($dataStale ?? null) !== null): ?>
		<div class="notice-warning">
			<p><?= $e($dataStale === 1
				? $t('The series data has not been updated since yesterday - the automatic update may have stopped. Please notify the administrator.')
				: $t('The series data has not been updated in %d days - the automatic update may have stopped. Please notify the administrator.', $dataStale)) ?></p>
		</div>
	<?php endif; ?>

	<?php foreach (($flashes ?? []) as $flash): ?>
		<div class="flash flash-<?= $e($flash['type']) ?>"><?= $e($flash['message']) ?></div>
	<?php endforeach; ?>

	<?= $content ?>
</main>

<footer class="footer">
	<div>Catenvis · <?= $e($t('Data from')) ?> <a href="https://www.themoviedb.org/" rel="noopener">TMDB</a></div>
	<div class="tmdb-disclaimer">This product uses the TMDB API but is not endorsed or certified by TMDB.</div>
</footer>
</body>
</html>
