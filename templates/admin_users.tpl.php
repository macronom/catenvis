<section>
	<h1><?= $e($t('User management')) ?></h1>

	<h2><?= $e($t('Create new user')) ?></h2>
	<form class="admin-form" method="post" action="<?= $e($url('/admin/users')) ?>">
		<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
		<label><?= $e($t('Username')) ?>
			<input type="text" name="username" required>
		</label>
		<label><?= $e($t('Default password (at least 8 characters)')) ?>
			<input type="text" name="password" required>
		</label>
		<label class="checkbox">
			<input type="checkbox" name="is_admin" value="1"> <?= $e($t('Administrator')) ?>
		</label>
		<button type="submit"><?= $e($t('Create user')) ?></button>
	</form>

	<h2><?= $e($t('Existing users')) ?></h2>
	<table class="user-table">
		<thead>
			<tr><th><?= $e($t('User')) ?></th><th><?= $e($t('Role')) ?></th><th><?= $e($t('Password change pending')) ?></th><th><?= $e($t('Last login')) ?></th></tr>
		</thead>
		<tbody>
			<?php foreach ($users as $u): ?>
				<tr>
					<td><?= $e($u['username']) ?></td>
					<td><?= ((int) $u['is_admin'] === 1) ? $e($t('Admin')) : $e($t('User')) ?></td>
					<td><?= ((int) $u['must_change_password'] === 1) ? $e($t('yes')) : $e($t('no')) ?></td>
					<td><?= $e($u['last_login_at'] ?? '–') ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
