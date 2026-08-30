<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

/** @var array<string, array{status: string, label: string}> $stores */
$stores = is_array($_['storesStatus'] ?? null) ? $_['storesStatus'] : [];
?>
<section class="ekc-card" aria-labelledby="ekc-stores-title">
	<header class="ekc-card__header">
		<h2 id="ekc-stores-title"><?php p($l->t('Which stores work')); ?></h2>
	</header>
	<div class="ekc-card__body">
		<p class="ekc-hint"><?php p($l->t('We only use official store lists. No fragile PDF scraping.')); ?></p>
		<ul class="ekc-store-status-list" aria-label="<?php p($l->t('Store status')); ?>">
			<?php foreach ($stores as $row): ?>
				<?php
				$status = (string)($row['status'] ?? '');
				$ok = $status === 'ok';
				$cls = $ok ? 'ok' : 'blocked';
				$badge = $ok ? $l->t('Available') : $l->t('Not yet');
				?>
				<li class="ekc-store-status ekc-store-status--<?php p($cls); ?>">
					<span class="ekc-pill <?php p($cls === 'ok' ? 'win' : 'match'); ?>"><?php p($badge); ?></span>
					<span><?php p((string)($row['label'] ?? '')); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
