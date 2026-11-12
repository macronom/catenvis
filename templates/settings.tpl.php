<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

/**
 * Erwartet: $sort, $view, $languages (ISO => Label) sowie die geteilten
 * $titleLang, $contentLang, $auth, $app, $e, $url.
 */
?>
<section class="settings">
	<h1><?= $e($t('Settings')) ?></h1>

	<?php if (!$auth->isAdmin()): ?>
		<?php
		// Each group is one POST form; the clicked button submits its value.
		$groups = [
			['label' => $t('Sort:'), 'field' => 'sort', 'current' => $sort === 'name' ? 'name' : 'default',
				'options' => ['default' => $t('Progress'), 'name' => $t('Name')]],
			['label' => $t('View:'), 'field' => 'view', 'current' => $view === 'list' ? 'list' : 'grid',
				'options' => ['grid' => $t('Tiles'), 'list' => $t('List')]],
			['label' => $t('Language:'), 'field' => 'lang', 'current' => $contentLang,
				'options' => $languages],
			['label' => $t('Series titles:'), 'field' => 'titlelang', 'current' => $titleLang === 'original' ? 'original' : 'own',
				'options' => ['own' => $t('My language'), 'original' => $t('Original')]],
		];
		?>
		<?php foreach ($groups as $group): ?>
			<form method="post" action="<?= $e($url('/settings')) ?>" class="sort-switch">
				<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
				<span><?= $e($group['label']) ?></span>
				<?php foreach ($group['options'] as $value => $label): ?>
					<button type="submit" name="<?= $e($group['field']) ?>" value="<?= $e($value) ?>" class="<?= $group['current'] === (string) $value ? 'active' : '' ?>"><?= $e($label) ?></button>
				<?php endforeach; ?>
			</form>
		<?php endforeach; ?>
	<?php endif; ?>

	<div class="settings-links">
		<?php if (!$auth->isAdmin()): ?>
			<a href="<?= $e($url('/import')) ?>"><?= $e($t('Import series from IMDb')) ?></a>
		<?php endif; ?>
		<a href="<?= $e($url('/change-password')) ?>"><?= $e($t('Change password')) ?></a>
	</div>
</section>
