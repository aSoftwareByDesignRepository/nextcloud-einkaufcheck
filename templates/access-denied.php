<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\EinkaufCheck\Service\IconCatalog;

$message = (string)($_['message'] ?? '');
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
?>
<div id="app-content" class="ekc-app ekc-app--denied">
	<div id="app-content-wrapper" class="ekc-shell ekc-shell--minimal">
		<main class="ekc-denied" role="alert" tabindex="-1">
			<div class="ekc-page-header__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('shield', 'ekc-page-header__icon-svg')); ?>
			</div>
			<h1><?php p($l->t('Access denied')); ?></h1>
			<p><?php p($message); ?></p>
			<?php if ($hint !== ''): ?>
				<p><?php p($hint); ?></p>
			<?php endif; ?>
			<p>
				<a class="ekc-btn ekc-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
			</p>
		</main>
	</div>
</div>
