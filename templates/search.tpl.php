<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom
?>
<section>
	<h1><?= $e($t('Search my series')) ?></h1>

	<form class="search-form" method="get" action="<?= $e($url('/search')) ?>">
		<input type="search" name="q" value="<?= $e($query) ?>" placeholder="<?= $e($t('Title or part of it …')) ?>" maxlength="<?= \Catenvis\SearchQuery::MAX_LENGTH ?>"<?= $searched ? '' : ' autofocus' ?>>
		<button type="submit"><?= $e($t('Search')) ?></button>
	</form>

	<?php if ($searched): ?>
		<?php $total = count($following) + count($stopped); ?>
		<p class="search-summary"><?= $e($total === 0
			? $t('No series of yours matches "%s".', $query)
			: ($total === 1 ? $t('One series matches "%s".', $query) : $t('%1$d series match "%2$s".', $total, $query))) ?></p>
	<?php endif; ?>

	<?php if (!empty($following)): ?>
		<div class="series-grid<?= $view === 'list' ? ' list-view' : '' ?>">
			<?php foreach ($following as $row): ?>
				<?= $app->view->capture('_partials/series_card', ['row' => $row]) ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if (!empty($stopped)): ?>
		<h2 class="stopped-heading"><?= $e($t('Stopped series')) ?></h2>
		<div class="series-grid stopped<?= $view === 'list' ? ' list-view' : '' ?>">
			<?php foreach ($stopped as $row): ?>
				<?= $app->view->capture('_partials/series_card', ['row' => $row]) ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
