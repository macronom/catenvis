<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom
?>
<section>
	<?php if (!empty($unavailable)): ?>
		<div class="notice-warning">
			<p><?= $e(count($unavailable) === 1
				? $t('One of your series could not be updated - it may have been removed or merged on TMDB:')
				: $t('%d of your series could not be updated - they may have been removed or merged on TMDB:', count($unavailable))) ?></p>
			<ul>
				<?php foreach ($unavailable as $row): ?>
					<li><a href="<?= $e($url('/series/' . (int) $row['id'])) ?>"><?= $e($title($row)) ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if (empty($following) && empty($stopped)): ?>
		<p class="empty"><?= $e($t('You are not following any series yet.')) ?> <a href="<?= $e($url('/add')) ?>"><?= $e($t('Add your first series now.')) ?></a></p>
	<?php endif; ?>

	<?php if (!empty($following)): ?>
		<div class="series-grid<?= $view === 'list' ? ' list-view' : '' ?>" id="following-grid">
			<?php foreach ($following as $row): ?>
				<?= $app->view->capture('_partials/series_card', ['row' => $row]) ?>
			<?php endforeach; ?>
		</div>
		<?php if ($followingTotal > count($following)): ?>
			<div class="load-more-wrap"
				data-url="<?= $e($url('/more')) ?>"
				data-status="following"
				data-target="following-grid"
				data-sort="<?= $e($sort) ?>"
				data-offset="<?= count($following) ?>"
				data-total="<?= (int) $followingTotal ?>"
				data-label-more="<?= $e($t('Load more (%1$d/%2$d)')) ?>">
				<button type="button" class="load-btn" data-mode="more"><?= $e($t('Load more (%1$d/%2$d)', count($following), (int) $followingTotal)) ?></button>
				<button type="button" class="load-btn btn-ghost" data-mode="all"><?= $e($t('Load all')) ?></button>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if (!empty($stopped)): ?>
		<h2 class="stopped-heading"><?= $e($t('Stopped series')) ?></h2>
		<div class="series-grid stopped<?= $view === 'list' ? ' list-view' : '' ?>" id="stopped-grid">
			<?php foreach ($stopped as $row): ?>
				<?= $app->view->capture('_partials/series_card', ['row' => $row]) ?>
			<?php endforeach; ?>
		</div>
		<?php if ($stoppedTotal > count($stopped)): ?>
			<div class="load-more-wrap"
				data-url="<?= $e($url('/more')) ?>"
				data-status="stopped"
				data-target="stopped-grid"
				data-sort="<?= $e($sort) ?>"
				data-offset="<?= count($stopped) ?>"
				data-total="<?= (int) $stoppedTotal ?>"
				data-label-more="<?= $e($t('Load more (%1$d/%2$d)')) ?>">
				<button type="button" class="load-btn" data-mode="more"><?= $e($t('Load more (%1$d/%2$d)', count($stopped), (int) $stoppedTotal)) ?></button>
				<button type="button" class="load-btn btn-ghost" data-mode="all"><?= $e($t('Load all')) ?></button>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if (($followingTotal > count($following)) || ($stoppedTotal > count($stopped))): ?>
	<script nonce="<?= $e($cspNonce) ?>">
	(function () {
		document.querySelectorAll('.load-more-wrap').forEach(function (wrap) {
			var target = document.getElementById(wrap.dataset.target);
			var moreBtn = wrap.querySelector('[data-mode="more"]');
			function buildUrl(all) {
				return wrap.dataset.url
					+ '?status=' + encodeURIComponent(wrap.dataset.status)
					+ '&sort=' + encodeURIComponent(wrap.dataset.sort)
					+ '&offset=' + encodeURIComponent(wrap.dataset.offset)
					+ (all ? '&all=1' : '');
			}
			function setBusy(busy) {
				wrap.querySelectorAll('.load-btn').forEach(function (b) { b.disabled = busy; });
			}
			wrap.querySelectorAll('.load-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var all = btn.dataset.mode === 'all';
					setBusy(true);
					fetch(buildUrl(all), { headers: { 'X-Requested-With': 'fetch' } })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							target.insertAdjacentHTML('beforeend', d.html);
							wrap.dataset.offset = d.nextOffset;
							if (d.hasMore) {
								setBusy(false);
								if (moreBtn) {
									moreBtn.textContent = wrap.dataset.labelMore
										.replace('%1$d', d.nextOffset)
										.replace('%2$d', wrap.dataset.total);
								}
							} else {
								wrap.parentNode.removeChild(wrap);
							}
						})
						.catch(function () { setBusy(false); });
				});
			});
		});
	})();
	</script>
<?php endif; ?>
