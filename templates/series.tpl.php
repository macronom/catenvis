<?php
$csrf = $app->csrfToken();
$watchAction = static function (string $scope, array $extra, string $action, string $label, string $class, string $confirm = '') use ($e, $url, $csrf, $series): string {
	$hidden = '';
	foreach ($extra as $k => $v) {
		$hidden .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
	}
	$confirmAttr = $confirm !== '' ? ' data-confirm="' . $e($confirm) . '"' : '';
	return '<form method="post" action="' . $e($url('/watch')) . '" class="inline-form"' . $confirmAttr . '>'
		. '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
		. '<input type="hidden" name="series_id" value="' . (int) $series['id'] . '">'
		. '<input type="hidden" name="scope" value="' . $e($scope) . '">'
		. '<input type="hidden" name="action" value="' . $e($action) . '">'
		. $hidden
		. '<button type="submit" class="' . $e($class) . '">' . $e($label) . '</button>'
		. '</form>';
};
$statusAction = static function (string $status, string $label, string $class = '') use ($e, $url, $csrf, $series): string {
	$classAttr = $class !== '' ? ' class="' . $e($class) . '"' : '';
	return '<form method="post" action="' . $e($url('/follow')) . '" class="inline-form">'
		. '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
		. '<input type="hidden" name="series_id" value="' . (int) $series['id'] . '">'
		. '<input type="hidden" name="status" value="' . $e($status) . '">'
		. '<button type="submit"' . $classAttr . '>' . $e($label) . '</button>'
		. '</form>';
};
?>
<section class="series-detail">
	<?php if (!empty($series['sync_error'])): ?>
		<div class="notice-warning">
			<?php if (!empty($series['sync_failed_at'])): ?>
				<p><?= $e($t('This series could not be updated since %s - it may have been removed or merged on TMDB.', $shortDate((string) $series['sync_failed_at']))) ?></p>
			<?php else: ?>
				<p><?= $e($t('This series could not be updated - it may have been removed or merged on TMDB.')) ?></p>
			<?php endif; ?>
			<p><?= $e($t('Check TMDB for a replacement; you can add the new entry and remove this one below.')) ?></p>
		</div>
	<?php endif; ?>
	<div class="series-header">
		<div class="poster">
			<?php if (!empty($series['poster_path']) && $imageBase !== ''): ?>
				<img src="<?= $e($imageBase . $series['poster_path']) ?>" alt="">
			<?php else: ?>
				<div class="poster-placeholder">📺</div>
			<?php endif; ?>
		</div>
		<div class="series-header-body">
			<h1><?= $e($title($series)) ?><?php if (!empty($series['first_air_year'])): ?> <span class="year">(<?= (int) $series['first_air_year'] ?>)</span><?php endif; ?></h1>
			<?php
			// Translated TMDB status tag: [label, CSS class] (shared helper).
			$statusTag = $seriesStatus($series['status'] ?? null);
			?>
			<?php
			$networks = [];
			if (!empty($series['networks'])) {
				$decoded = json_decode((string) $series['networks'], true);
				$networks = is_array($decoded) ? $decoded : [];
			}
			?>
			<div class="meta-row">
				<?php if ($statusTag !== null): ?><p class="status"><?= $e($t('Status:')) ?> <span class="status-tag status-<?= $statusTag[1] ?>"><?= $e($statusTag[0]) ?></span></p><?php endif; ?>
				<?php if ($networks !== []): ?>
					<p class="networks"><span class="networks-label"><?= $e($t('Network:')) ?></span>
						<?php foreach ($networks as $n): ?>
							<?php if (!empty($n['logo_path'])): ?>
								<img class="network-logo" src="<?= $e($logoBase . $n['logo_path']) ?>" alt="<?= $e($n['name'] ?? '') ?>" title="<?= $e($n['name'] ?? '') ?>" loading="lazy">
							<?php else: ?>
								<span class="network-name"><?= $e($n['name'] ?? '') ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>
			<?php if (!empty($series['next_air_date'])): ?><p class="next"><?= $e($t('Next episode:')) ?> <?= $e($series['next_air_date']) ?></p><?php endif; ?>
			<?php if (!empty($series['overview'])): ?><p class="overview"><?= $e($series['overview']) ?></p><?php endif; ?>

			<p class="ext-links">
				<a href="https://www.themoviedb.org/tv/<?= (int) $series['id'] ?>" target="_blank" rel="noopener">TMDB ↗</a>
				<?php if (!empty($series['imdb_id'])): ?>
					<a href="https://www.imdb.com/title/<?= $e($series['imdb_id']) ?>/" target="_blank" rel="noopener">IMDb ↗</a>
				<?php endif; ?>
			</p>

			<div class="actions">
				<?php if ($followStatus === 'following'): ?>
					<?= $statusAction('stopped', $t('Stop series'), 'btn-secondary') ?>
					<?= $statusAction('deferred', $t('Defer series'), 'btn-secondary') ?>
				<?php elseif ($followStatus === 'deferred'): ?>
					<?= $statusAction('stopped', $t('Stop series'), 'btn-secondary') ?>
					<?= $statusAction('following', $t('Activate series')) ?>
				<?php else: ?>
					<?= $statusAction('following', $t('Follow again')) ?>
				<?php endif; ?>
				<form method="post" action="<?= $e($url('/remove')) ?>" class="inline-form" data-confirm="<?= $e($t('Really remove this series? Your progress data for it will be lost.')) ?>">
					<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
					<input type="hidden" name="series_id" value="<?= (int) $series['id'] ?>">
					<button type="submit" class="btn-danger"><?= $e($t('Remove series')) ?></button>
				</form>
			</div>
			<div class="actions">
				<?= $watchAction('series', [], 'watch', $t('Mark all as watched'), 'btn-secondary') ?>
				<?= $watchAction('series', [], 'unwatch', $t('Reset all'), 'btn-ghost', $t('Really remove all watched marks of this series?')) ?>
			</div>
		</div>
	</div>

	<?php foreach ($seasons as $seasonNumber => $episodes): ?>
		<?php
		$seenInSeason = 0;
		$airedInSeason = 0;
		$unseenAired = 0;
		foreach ($episodes as $ep) {
			$seen  = isset($watched[(int) $ep['id']]);
			$aired = !empty($ep['air_date']) && $ep['air_date'] <= $today;
			if ($seen) {
				$seenInSeason++;
			}
			if ($aired) {
				$airedInSeason++;
			}
			if ($aired && !$seen) {
				$unseenAired++;
			}
		}
		// Eine Staffel gilt als "aktiv", solange noch Folgen ausstehen (nicht ausgestrahlt).
		$hasUpcoming = $airedInSeason < count($episodes);
		// Nur abgeschlossene, komplett gesehene Staffeln werden zugeklappt.
		$fullyWatched = $unseenAired === 0 && $seenInSeason > 0 && !$hasUpcoming;
		?>
		<details class="season<?= $fullyWatched ? ' done' : '' ?>"<?= $fullyWatched ? '' : ' open' ?>>
			<summary class="season-head">
				<h2><?= $e($t('Season %d', (int) $seasonNumber)) ?></h2>
				<span class="season-progress"><?= $e($t('%1$d / %2$d watched', $seenInSeason, $airedInSeason)) ?><?php if ($unseenAired > 0): ?><?= $e($t(' · %d new', $unseenAired)) ?><?php endif; ?></span>
			</summary>
			<div class="actions season-actions">
				<?= $watchAction('season', ['season_number' => (string) $seasonNumber], 'watch', $t('Season watched'), 'btn-small') ?>
				<?= $watchAction('season', ['season_number' => (string) $seasonNumber], 'unwatch', $t('reset'), 'btn-small btn-ghost') ?>
			</div>
			<ul class="episode-list">
				<?php foreach ($episodes as $ep): ?>
					<?php
					$epId    = (int) $ep['id'];
					$isSeen  = isset($watched[$epId]);
					$aired   = !empty($ep['air_date']) && $ep['air_date'] <= $today;
					$rowClass = $isSeen ? 'seen' : ($aired ? 'unseen' : 'upcoming');
					?>
					<?php
					$epNum      = $e($episodeCode((int) $seasonNumber, (int) $ep['episode_number']));
					$epName     = $e($ep['name'] ?? '');
					$epDate     = $e($ep['air_date'] ?? '');
					$epOverview = trim((string) ($ep['overview'] ?? ''));
					?>
					<li class="episode <?= $rowClass ?>">
						<span class="ep-num"><?= $epNum ?></span>
						<?php if ($epOverview !== ''): ?>
							<button type="button" class="ep-name ep-expand" aria-expanded="false"><?= $epName ?></button>
						<?php else: ?>
							<span class="ep-name"><?= $epName ?></span>
						<?php endif; ?>
						<span class="ep-date"><?= $epDate ?></span>
						<span class="ep-toggle">
							<?php if (!$aired && !$isSeen): ?>
								<span class="upcoming-label"><?= $e($t('upcoming')) ?></span>
							<?php elseif ($isSeen): ?>
								<?= $watchAction('episode', ['episode_id' => (string) $epId], 'unwatch', $t('✓ watched'), 'btn-check seen') ?>
							<?php else: ?>
								<?= $watchAction('episode', ['episode_id' => (string) $epId], 'watch', $t('mark watched'), 'btn-check') ?>
							<?php endif; ?>
						</span>
						<?php if ($epOverview !== ''): ?>
							<p class="ep-overview" hidden><?= $e($epOverview) ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endforeach; ?>

	<?php if (empty($seasons)): ?>
		<p class="empty"><?= $e($t('No episodes stored for this series yet.')) ?></p>
	<?php endif; ?>
</section>

<script nonce="<?= $e($cspNonce) ?>">
(function () {
	document.querySelectorAll('form[data-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (!window.confirm(form.dataset.confirm)) {
				e.preventDefault();
			}
		});
	});

	document.querySelectorAll('.ep-expand').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var overview = btn.closest('.episode').querySelector('.ep-overview');
			if (!overview) {
				return;
			}
			var show = overview.hidden;
			overview.hidden = !show;
			btn.setAttribute('aria-expanded', show ? 'true' : 'false');
		});
	});
})();
</script>
