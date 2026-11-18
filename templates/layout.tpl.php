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
				<a href="<?= $e($url('/stats')) ?>"><?= $e($t('Statistics')) ?></a>
				<a href="<?= $e($url('/add')) ?>"><?= $e($t('Add series')) ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
	<?php if ($auth->isLoggedIn()): ?>
		<div class="userbox">
			<?php if (!$auth->isAdmin() && !$auth->mustChangePassword()): ?>
				<form class="topbar-search" method="get" action="<?= $e($url('/search')) ?>" role="search">
					<input type="search" name="q" id="topbar-search-input" placeholder="<?= $e($t('Search my series')) ?>" aria-label="<?= $e($t('Search my series')) ?>" maxlength="<?= \Catenvis\SearchQuery::MAX_LENGTH ?>" hidden>
					<a class="search-toggle" href="<?= $e($url('/search')) ?>" title="<?= $e($t('Search my series')) ?>" aria-label="<?= $e($t('Search my series')) ?>" aria-controls="topbar-search-input" aria-expanded="false">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					</a>
				</form>
			<?php endif; ?>
			<a class="user-link" href="<?= $e($url('/settings')) ?>"><?= $e($currentUser['username'] ?? '') ?></a>
			<form method="post" action="<?= $e($url('/logout')) ?>" class="inline-form">
				<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
				<button type="submit" class="logout-link" title="<?= $e($t('Log out')) ?>" aria-label="<?= $e($t('Log out')) ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
				</button>
			</form>
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
<?php if ($auth->isLoggedIn() && !$auth->isAdmin() && !$auth->mustChangePassword()): ?>
	<script nonce="<?= $e($cspNonce) ?>">
	(function () {
		// The magnifier opens the search field; without JavaScript it is a
		// plain link to the search page.
		var form = document.querySelector('.topbar-search');
		if (!form) {
			return;
		}
		var input = form.querySelector('input');
		var toggle = form.querySelector('.search-toggle');
		function setOpen(open) {
			input.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				input.focus();
			}
		}
		toggle.addEventListener('click', function (ev) {
			ev.preventDefault();
			if (input.hidden) {
				setOpen(true);
			} else if (input.value.trim() !== '') {
				form.submit();
			} else {
				setOpen(false);
			}
		});
		form.addEventListener('submit', function (ev) {
			if (input.value.trim() === '') {
				ev.preventDefault();
			}
		});
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') {
				input.value = '';
				setOpen(false);
				toggle.focus();
			}
		});
		// Clicking the magnifier must not take the focus from the field, so
		// the blur handler below only sees real focus changes.
		toggle.addEventListener('mousedown', function (ev) {
			ev.preventDefault();
		});
		input.addEventListener('blur', function () {
			if (input.value.trim() === '') {
				setOpen(false);
			}
		});
	})();
	</script>
<?php endif; ?>
</body>
</html>
