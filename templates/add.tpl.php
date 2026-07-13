<section>
	<h1><?= $e($t('Add series')) ?></h1>

	<form class="search-form" method="get" action="<?= $e($url('/add')) ?>">
		<input type="search" name="q" value="<?= $e($query) ?>" placeholder="<?= $e($t('Search series title …')) ?>" autofocus>
		<button type="submit"><?= $e($t('Search')) ?></button>
	</form>

	<?php if (!empty($error)): ?>
		<div class="flash flash-error"><?= $e($error) ?></div>
	<?php endif; ?>

	<?php if ($query !== '' && empty($results) && empty($error)): ?>
		<p class="empty"><?= $e($t('No results for "%s".', $query)) ?></p>
	<?php endif; ?>

	<?php foreach ($results as $item): ?>
		<?php
		$year = !empty($item['first_air_date']) ? substr((string) $item['first_air_date'], 0, 4) : '';
		// Secondary title by original language: series in the user's own language
		// without an addition; English ones with the original title; other
		// languages only get the English title when they lack their own
		// translation (name == original_name means "no translation into the
		// user's own language available").
		$mainTitle = (string) ($item['name'] ?? '');
		$origTitle = (string) ($item['original_name'] ?? '');
		$origLang  = (string) ($item['original_language'] ?? '');
		$altTitle  = '';
		if ($origLang === 'en') {
			$altTitle = $origTitle;
		} elseif ($origLang !== $contentLang && $mainTitle === $origTitle) {
			$altTitle = (string) ($item['title_en'] ?? '');
		}
		if ($altTitle === $mainTitle) {
			$altTitle = '';
		}
		?>
		<div class="search-result">
			<div class="poster small">
				<?php if (!empty($item['poster_path']) && $imageBase !== ''): ?>
					<img src="<?= $e($imageBase . $item['poster_path']) ?>" alt="" loading="lazy">
				<?php else: ?>
					<div class="poster-placeholder">📺</div>
				<?php endif; ?>
			</div>
			<div class="search-result-body">
				<h3><?= $e($mainTitle) ?><?php if ($year !== ''): ?> <span class="year">(<?= $e($year) ?>)</span><?php endif; ?><?php if ($altTitle !== '' && $altTitle !== $mainTitle): ?> <span class="alt-title"><?= $e($altTitle) ?></span><?php endif; ?></h3>
				<p class="overview"><?= $e(mb_strimwidth((string) ($item['overview'] ?? ''), 0, 240, '…')) ?></p>
			</div>
			<form method="post" action="<?= $e($url('/add')) ?>">
				<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
				<input type="hidden" name="series_id" value="<?= (int) ($item['id'] ?? 0) ?>">
				<button type="submit"><?= $e($t('Follow')) ?></button>
			</form>
		</div>
	<?php endforeach; ?>
</section>
