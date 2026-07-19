<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

$done     = (int) ($counts['done'] ?? 0);
$skipped  = (int) ($counts['skipped'] ?? 0);
$notfound = (int) ($counts['notfound'] ?? 0);
$failed   = (int) ($counts['failed'] ?? 0);
$total    = $done + $skipped + $notfound + $failed + (int) $pending;
$processedCount = $total - (int) $pending;
?>
<section class="import-page">
	<h1><?= $e($t('Import series from IMDb')) ?></h1>

	<?php if ($total > 0): ?>
		<div class="import-result" id="import-status" data-status-url="<?= $e($url('/import/status')) ?>">
			<?php if ($pending > 0): ?>
				<h2><?= $e($t('Import running …')) ?> <span class="spinner">⏳</span></h2>
				<div class="progress">
					<div class="progress-bar" id="imp-bar" style="width: <?= $total > 0 ? (int) round($processedCount / $total * 100) : 0 ?>%"></div>
				</div>
				<?php // Rich string: the placeholders receive template-built HTML counters. ?>
				<p><?= $t('%1$s of %2$s processed – %3$s in the queue.',
					'<span id="imp-processed">' . $processedCount . '</span>',
					'<span id="imp-total">' . $total . '</span>',
					'<strong id="imp-pending">' . (int) $pending . '</strong>') ?></p>
			<?php else: ?>
				<h2><?= $e($t('Import finished')) ?></h2>
			<?php endif; ?>

			<ul class="result-summary">
				<li><strong id="imp-done"><?= $done ?></strong> <?= $e($t('added')) ?></li>
				<li><strong id="imp-skipped"><?= $skipped ?></strong> <?= $e($t('already present')) ?></li>
				<li><strong id="imp-notfound"><?= $notfound ?></strong> <?= $e($t('not found')) ?></li>
				<li><strong id="imp-failed"><?= $failed ?></strong> <?= $e($t('failed')) ?></li>
			</ul>

			<?php
			$groups = [
				'done'     => $t('Added'),
				'notfound' => $t('Not found'),
				'failed'   => $t('Failed'),
			];
			foreach ($groups as $status => $label):
				$items = array_filter($results, static fn(array $r): bool => $r['status'] === $status);
				if (empty($items)) {
					continue;
				}
				?>
				<details<?= $status === 'done' ? ' open' : '' ?>>
					<summary><?= $e($label) ?> (<?= count($items) ?>)</summary>
					<ul>
						<?php foreach ($items as $r): ?>
							<li><?= $e($r['title'] ?? $r['imdb_id']) ?><?php if (!empty($r['message']) && $status !== 'done'): ?> <span class="muted">– <?= $e($t($r['message'])) ?></span><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endforeach; ?>

			<?php if ($pending === 0): ?>
				<p><a href="<?= $e($url('/')) ?>"><?= $e($t('Back to overview')) ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ($pending === 0): ?>
		<form class="import-form" method="post" action="<?= $e($url('/import')) ?>" enctype="multipart/form-data">
			<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
			<label><?= $e($t('IMDb CSV file (ratings or watchlist)')) ?>
				<input type="file" name="csv" accept=".csv,text/csv" required>
			</label>
			<label class="checkbox">
				<input type="checkbox" name="mark_seen" value="1"> <?= $e($t('Mark all episodes of the imported series as "watched"')) ?>
			</label>
			<p class="hint"><?= $e($t('For running series only fully aired (old) seasons are marked; the current season stays open.')) ?></p>
			<button type="submit"><?= $e($t('Start import')) ?></button>
			<p class="hint"><?= $e($t('Only series (tvSeries / tvMiniSeries) are imported. Movies and single episodes are ignored. Existing series stay untouched. The import runs in the background – you can leave this page and come back later.')) ?></p>
		</form>

		<div class="import-help">
			<h2><?= $e($t('How to export your data from IMDb')) ?></h2>
			<?php // Rich strings: these catalog values may contain simple inline markup. ?>
			<ol>
				<li><?= $t('Log in at <a href="https://www.imdb.com/" target="_blank" rel="noopener">IMDb</a>.') ?></li>
				<li><?= $t('For <strong>rated series</strong>: profile → <em>Your Ratings</em>. For the <strong>watchlist</strong>: profile → <em>Watchlist</em>.') ?></li>
				<li><?= $t('On the list page, open the <em>…</em> menu at the top right and choose <strong>Export</strong>.') ?></li>
				<li><?= $t('IMDb creates a CSV file (e.g. <code>ratings.csv</code>) for download.') ?></li>
				<li><?= $t('Upload that file here.') ?></li>
			</ol>
			<p class="hint"><?= $t('Matching uses the exact IMDb id (column <code>Const</code>), not the title – so there are no mix-ups.') ?></p>
		</div>
	<?php endif; ?>
</section>

<?php if ($pending > 0): ?>
	<script nonce="<?= $e($cspNonce) ?>">
	(function () {
		var box = document.getElementById('import-status');
		if (!box) {
			return;
		}
		var url = box.dataset.statusUrl;
		function tick() {
			fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					var c = d.counts;
					var pending = d.pending;
					var total = c.done + c.skipped + c.notfound + c.failed + pending;
					var processed = total - pending;
					document.getElementById('imp-done').textContent = c.done;
					document.getElementById('imp-skipped').textContent = c.skipped;
					document.getElementById('imp-notfound').textContent = c.notfound;
					document.getElementById('imp-failed').textContent = c.failed;
					document.getElementById('imp-processed').textContent = processed;
					document.getElementById('imp-total').textContent = total;
					document.getElementById('imp-pending').textContent = pending;
					var bar = document.getElementById('imp-bar');
					if (bar) {
						bar.style.width = (total ? Math.round(processed / total * 100) : 0) + '%';
					}
					if (pending === 0) {
						// Fertig: einmal neu laden, um Endzusammenfassung und Formular zu zeigen.
						location.reload();
						return;
					}
					setTimeout(tick, 3000);
				})
				.catch(function () { setTimeout(tick, 5000); });
		}
		setTimeout(tick, 3000);
	})();
	</script>
<?php endif; ?>
