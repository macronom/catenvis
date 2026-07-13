<?php
/**
 * Expects: $row (series with aired_count/watched_count/unseen_count/upcoming_count/status
 *          as well as next_unseen_id/_season/_episode/_name), $imageBase, $e, $url.
 */
$aired    = (int) $row['aired_count'];
$watched  = (int) $row['watched_count'];
$unseen   = (int) $row['unseen_count'];
$upcoming = (int) ($row['upcoming_count'] ?? 0);
$percent  = $aired > 0 ? (int) round($watched / $aired * 100) : 0;
// Date of the next upcoming episode (first episode of the coming season).
$nextDate = !empty($row['next_ep']) ? (string) $row['next_ep'] : (string) ($row['next_air_date'] ?? '');
// Next upcoming episode is a season premiere (E01) -> season not started yet.
$newSeason = ((int) ($row['next_up_episode'] ?? 0)) === 1;

// Active = followed or deferred; both keep badges and quick action.
$active = in_array($row['status'], ['following', 'deferred'], true);
// Hover quick action: only active series with unseen episodes that have already aired.
$nextUnseenId = (int) ($row['next_unseen_id'] ?? 0);
$quickWatch   = $active && $unseen > 0 && $nextUnseenId > 0;

// Poster tooltip: summarizes the badges hidden on hover as a short text
// (the badges themselves therefore carry no title attributes anymore).
$posterTitle = '';
if ($active && $unseen > 0) {
	$posterTitle = $unseen === 1 ? $t('one new episode') : $t('%d new episodes', $unseen);
	if (($row['next_unseen_season'] ?? null) !== null) {
		$posterTitle .= $t(', continue with %s', $episodeCode((int) $row['next_unseen_season'], (int) $row['next_unseen_episode']));
	}
	if ($row['status'] === 'deferred') {
		$posterTitle = $t('deferred – %s', $posterTitle);
	}
} elseif ($active && $upcoming > 0) {
	if ($newSeason) {
		$posterTitle = $upcoming === 1 ? $t('one episode in the upcoming season') : $t('%d episodes in the upcoming season', $upcoming);
		if ($nextDate !== '') {
			$posterTitle .= $t(', the first on %s', $shortDate($nextDate));
		}
	} else {
		$posterTitle = $upcoming === 1 ? $t('one more episode') : $t('%d more episodes', $upcoming);
		if ($nextDate !== '') {
			$posterTitle .= $t(', the next on %s', $shortDate($nextDate));
		}
	}
}

// Translated TMDB status tag: [label, CSS class] (shared helper).
$statusTag = $seriesStatus($row['series_status'] ?? null);
$seriesUrl = $url('/series/' . (int) $row['id']);
// Second title line "(year) status" – hidden on hover as well, so the quick
// action form has room without changing the card height.
$titleExtra = !empty($row['first_air_year']) || $statusTag !== null;
?>
<div class="series-card<?= $quickWatch ? ' has-quick-watch' : '' ?><?= $quickWatch && !$titleExtra ? ' no-title-extra' : '' ?>">
	<a class="card-poster-link" href="<?= $e($seriesUrl) ?>" tabindex="-1">
		<div class="poster"<?= $posterTitle !== '' ? ' title="' . $e($posterTitle) . '"' : '' ?>>
			<?php if (!empty($row['poster_path']) && $imageBase !== ''): ?>
				<img src="<?= $e($imageBase . $row['poster_path']) ?>" alt="" loading="lazy">
			<?php else: ?>
				<div class="poster-placeholder">📺</div>
			<?php endif; ?>
			<div class="poster-badges">
				<?php if ($active && $unseen > 0): ?>
					<span class="badge<?= $row['status'] === 'deferred' ? ' badge-deferred' : '' ?>"><?= $unseen ?></span>
				<?php elseif ($active && $upcoming > 0): ?>
					<span class="badge <?= $newSeason ? 'badge-upcoming' : 'badge-airing' ?>"><?= $upcoming ?></span>
				<?php endif; ?>
			</div>
			<?php if ($active && $unseen > 0 && ($row['next_unseen_season'] ?? null) !== null): ?>
				<span class="next-ep-badge"><?= $e($episodeCode((int) $row['next_unseen_season'], (int) $row['next_unseen_episode'])) ?></span>
			<?php elseif ($active && $unseen === 0 && $upcoming > 0 && $nextDate !== ''): ?>
				<span class="next-ep-badge"><?= $e($shortDate($nextDate)) ?></span>
			<?php endif; ?>
		</div>
	</a>
	<div class="series-card-body">
		<h3 title="<?= $e($title($row)) ?>"><a class="card-title-link" href="<?= $e($seriesUrl) ?>"><span class="series-title"><?= $e($title($row)) ?></span><?php if ($titleExtra): ?><span class="card-title-extra"><?php if (!empty($row['first_air_year'])): ?> <span class="year">(<?= (int) $row['first_air_year'] ?>)</span><?php endif; ?><?php if ($statusTag !== null): ?> <span class="status-tag status-<?= $statusTag[1] ?>"><?= $e($statusTag[0]) ?></span><?php endif; ?></span><?php endif; ?></a></h3>
		<div class="card-meta">
			<div class="progress" title="<?= $e($t('%1$d of %2$d aired episodes watched', $watched, $aired)) ?>">
				<div class="progress-bar" style="width: <?= $percent ?>%"></div>
			</div>
			<small><?= $e($t('%1$d / %2$d watched', $watched, $aired)) ?></small>
		</div>
		<?php if ($quickWatch): ?>
			<?php
			// Display text: episode code plus episode title, if available.
			$epCode = $episodeCode((int) $row['next_unseen_season'], (int) $row['next_unseen_episode']);
			$epName = trim((string) ($row['next_unseen_name'] ?? ''));
			$epText = $epName !== '' ? $epCode . ' · ' . $epName : $epCode;
			?>
			<form method="post" action="<?= $e($url('/watch')) ?>" class="card-watch-form">
				<input type="hidden" name="_csrf" value="<?= $e($app->csrfToken()) ?>">
				<input type="hidden" name="series_id" value="<?= (int) $row['id'] ?>">
				<input type="hidden" name="scope" value="episode">
				<input type="hidden" name="action" value="watch">
				<input type="hidden" name="episode_id" value="<?= $nextUnseenId ?>">
				<input type="hidden" name="return" value="dashboard">
				<span class="card-watch-ep" title="<?= $e($epText) ?>"><?= $e($epText) ?></span>
				<button type="submit" class="btn-small"><?= $e($t('watched')) ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
