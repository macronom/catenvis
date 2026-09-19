<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

?>
<h1><?= $e($t('Statistics')) ?></h1>

<section class="stats-section">
	<h2><?= $e($t('Your collection')) ?></h2>
	<div class="stats-grid">
		<div class="stat-card"><div class="num"><?= (int) $collection['following'] ?></div><div class="label"><?= $e($t('Following')) ?></div></div>
		<div class="stat-card"><div class="num"><?= (int) $collection['deferred'] ?></div><div class="label"><?= $e($t('Deferred')) ?></div></div>
		<div class="stat-card"><div class="num"><?= (int) $collection['stopped'] ?></div><div class="label"><?= $e($t('Stopped')) ?></div></div>
	</div>
	<?php
	$prod = [
		['airing',       $t('Currently airing'), 'var(--success)'],
		['soon',         $t('Coming soon'),      'var(--airing)'],
		['inproduction', $t('In production'),    'var(--accent)'],
		['idle',         $t('Inactive'),         'var(--deferred)'],
		['ended',        $t('Ended'),            'var(--ended)'],
		['canceled',     $t('Canceled'),         'var(--danger)'],
	];
	$prodTotal = 0;
	foreach ($prod as $p) {
		$prodTotal += (int) $collection[$p[0]];
	}
	?>
	<?php if ($prodTotal > 0): ?>
		<p class="subhead"><?= $e($t('Production status of your followed series')) ?></p>
		<?php
		// Two stacked bars: still-active series on top, finished (ended/canceled)
		// below. The larger group spans the full width, the other one is
		// shortened in proportion.
		$prodGroups = [array_slice($prod, 0, 4), array_slice($prod, 4)];
		$groupTotals = [];
		foreach ($prodGroups as $i => $group) {
			$groupTotals[$i] = 0;
			foreach ($group as $p) {
				$groupTotals[$i] += (int) $collection[$p[0]];
			}
		}
		$largestGroup = max($groupTotals);
		?>
		<div class="dist-bars">
			<?php foreach ($prodGroups as $i => $group): ?>
				<?php $groupTotal = $groupTotals[$i]; ?>
				<?php if ($groupTotal > 0): ?>
					<div class="dist-bar" style="width: <?= 100 * $groupTotal / $largestGroup ?>%">
						<?php foreach ($group as $p): ?>
							<?php if ((int) $collection[$p[0]] > 0): ?>
								<span style="width: <?= 100 * (int) $collection[$p[0]] / $groupTotal ?>%; background: <?= $p[2] ?>"></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<div class="dist-legend">
			<?php foreach ($prod as $p): ?>
				<span><i style="background: <?= $p[2] ?>"></i><?= $e($p[1]) ?> · <?= (int) $collection[$p[0]] ?></span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<section class="stats-section">
	<h2><?= $e($t('Your progress')) ?></h2>
	<p class="stats-sub"><?= $e($t('Everything you have marked as watched.')) ?></p>
	<div class="stats-grid">
		<div class="stat-card accent"><div class="num"><?= $e($duration((int) $watch['minutes'])) ?></div><div class="label"><?= $e($t('Total time watched')) ?></div></div>
		<div class="stat-card"><div class="num"><?= $e($duration((int) $backlog['minutes'])) ?></div><div class="label"><?= $e($t('Still to catch up')) ?></div></div>
		<div class="stat-card"><div class="num"><?= (int) $watch['episodes'] ?></div><div class="label"><?= $e($t('Episodes watched')) ?></div></div>
		<div class="stat-card"><div class="num"><?= (int) $completed ?></div><div class="label"><?= $e($t('Series fully watched')) ?></div></div>
	</div>
</section>

<section class="stats-section">
	<h2><?= $e($t('Activity over time')) ?></h2>
	<p class="stats-sub"><?= $e($t('Watched time per week (minutes).')) ?></p>
	<?php $maxW = 0; foreach ($weeks as $w) { $maxW = max($maxW, (int) $w['minutes']); } ?>
	<div class="activity">
		<?php foreach ($weeks as $w): ?>
			<?php $h = ($maxW > 0 && (int) $w['minutes'] > 0) ? (int) round(110 * (int) $w['minutes'] / $maxW) : 0; ?>
			<div class="col">
				<span class="val"><?= (int) $w['minutes'] > 0 ? (int) $w['minutes'] : '' ?></span>
				<span class="bar" style="height: <?= $h ?>px"></span>
				<span class="wk"><?= $e($t('W%d', (int) $w['week_num'])) ?></span>
			</div>
		<?php endforeach; ?>
	</div>
	<p class="stats-sub" style="margin-top:1rem"><?= $e($t('Period: last %d weeks', (int) $activityWeeks)) ?></p>
</section>

<?php if (!empty($top)): ?>
<section class="stats-section">
	<h2><?= $e($t('Top series by time')) ?></h2>
	<?php $maxMin = (int) ($top[0]['minutes'] ?? 0); ?>
	<ul class="top-list" id="top-list">
		<?php foreach ($top as $i => $row): ?>
			<?= $app->view->capture('_partials/stat_top_row', ['row' => $row, 'rank' => (int) $i + 1, 'max' => $maxMin]) ?>
		<?php endforeach; ?>
	</ul>
	<?php if (!empty($topHasMore)): ?>
		<div class="load-more-wrap" id="top-more"
			data-url="<?= $e($url('/stats/top')) ?>"
			data-offset="<?= count($top) ?>"
			data-max="<?= $maxMin ?>">
			<button type="button" class="more-link"><?= $e($t('Show more')) ?></button>
		</div>
	<?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($topHasMore)): ?>
<script nonce="<?= $e($cspNonce) ?>">
(function () {
	var wrap = document.getElementById('top-more');
	if (!wrap) { return; }
	var list = document.getElementById('top-list');
	var btn  = wrap.querySelector('button');
	btn.addEventListener('click', function () {
		btn.disabled = true;
		fetch(wrap.dataset.url + '?offset=' + encodeURIComponent(wrap.dataset.offset) + '&max=' + encodeURIComponent(wrap.dataset.max),
			{ headers: { 'X-Requested-With': 'fetch' } })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				list.insertAdjacentHTML('beforeend', d.html);
				wrap.dataset.offset = d.nextOffset;
				if (d.hasMore) { btn.disabled = false; } else { wrap.parentNode.removeChild(wrap); }
			})
			.catch(function () { btn.disabled = false; });
	});
})();
</script>
<?php endif; ?>
