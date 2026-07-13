<section class="error-page">
	<h1><?= $e($t('Oops …')) ?></h1>
	<p><?= $e($message ?? $t('An error occurred.')) ?></p>
	<p><a href="<?= $e($url('/')) ?>"><?= $e($t('Back to the start page')) ?></a></p>
</section>
