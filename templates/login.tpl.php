<section class="auth-card">
	<h1><?= $e($t('Log in')) ?></h1>
	<form method="post" action="<?= $e($url('/login')) ?>">
		<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
		<label><?= $e($t('Username')) ?>
			<input type="text" name="username" autocomplete="username" required autofocus>
		</label>
		<label><?= $e($t('Password')) ?>
			<input type="password" name="password" autocomplete="current-password" required>
		</label>
		<button type="submit"><?= $e($t('Log in')) ?></button>
	</form>
</section>
