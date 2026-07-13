<?php
/**
 * Erwartet: $sort, $view, $languages (ISO => Label) sowie die geteilten
 * $titleLang, $contentLang, $auth, $e, $url.
 */
?>
<section class="settings">
	<h1><?= $e($t('Settings')) ?></h1>

	<?php if (!$auth->isAdmin()): ?>
		<div class="sort-switch">
			<span><?= $e($t('Sort:')) ?></span>
			<a href="<?= $e($url('/settings?sort=default')) ?>" class="<?= $sort !== 'name' ? 'active' : '' ?>"><?= $e($t('Progress')) ?></a>
			<a href="<?= $e($url('/settings?sort=name')) ?>" class="<?= $sort === 'name' ? 'active' : '' ?>"><?= $e($t('Name')) ?></a>
		</div>
		<div class="sort-switch">
			<span><?= $e($t('View:')) ?></span>
			<a href="<?= $e($url('/settings?view=grid')) ?>" class="<?= $view !== 'list' ? 'active' : '' ?>"><?= $e($t('Tiles')) ?></a>
			<a href="<?= $e($url('/settings?view=list')) ?>" class="<?= $view === 'list' ? 'active' : '' ?>"><?= $e($t('List')) ?></a>
		</div>
		<div class="sort-switch">
			<span><?= $e($t('Language:')) ?></span>
			<?php foreach ($languages as $code => $label): ?>
				<a href="<?= $e($url('/settings?lang=' . $code)) ?>" class="<?= $contentLang === $code ? 'active' : '' ?>"><?= $e($label) ?></a>
			<?php endforeach; ?>
		</div>
		<div class="sort-switch">
			<span><?= $e($t('Series titles:')) ?></span>
			<a href="<?= $e($url('/settings?titlelang=own')) ?>" class="<?= $titleLang !== 'original' ? 'active' : '' ?>"><?= $e($t('My language')) ?></a>
			<a href="<?= $e($url('/settings?titlelang=original')) ?>" class="<?= $titleLang === 'original' ? 'active' : '' ?>"><?= $e($t('Original')) ?></a>
		</div>
	<?php endif; ?>

	<p class="settings-password"><a href="<?= $e($url('/change-password')) ?>"><?= $e($t('Change password')) ?></a></p>
</section>
