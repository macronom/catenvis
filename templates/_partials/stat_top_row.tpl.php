<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 macronom

?>
<li>
	<span class="rank"><?= (int) $rank ?></span>
	<span class="name"><a href="<?= $e($url('/series/' . (int) $row['id'])) ?>"><?= $e($title($row)) ?></a></span>
	<span class="tmin"><?= $e($t('%d ep.', (int) $row['episodes'])) ?> · <?= $e($duration((int) $row['minutes'])) ?></span>
	<span class="bar"><span style="width: <?= (int) $max > 0 ? round(100 * (int) $row['minutes'] / (int) $max) : 0 ?>%"></span></span>
</li>
