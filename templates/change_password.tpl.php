<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom
?>
<section class="auth-card">
	<h1><?= $e($t('Change password')) ?></h1>
	<?php if ($auth->mustChangePassword()): ?>
		<p class="hint"><?= $e($t('Please choose your own password on first login.')) ?></p>
	<?php endif; ?>
	<form method="post" action="<?= $e($url('/change-password')) ?>">
		<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
		<label><?= $e($t('Current password')) ?>
			<input type="password" name="current_password" autocomplete="current-password" required autofocus>
		</label>
		<label><?= $e($t('New password (at least 8 characters)')) ?>
			<input type="password" name="password" autocomplete="new-password" required>
		</label>
		<label><?= $e($t('Confirm password')) ?>
			<input type="password" name="password_confirm" autocomplete="new-password" required>
		</label>
		<button type="submit"><?= $e($t('Save password')) ?></button>
	</form>
</section>
