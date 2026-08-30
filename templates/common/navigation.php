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

$pageId = (string)($_['pageId'] ?? 'offers');
$isAppAdmin = !empty($_['isAppAdmin']);
$settingsSection = (string)($_['settingsSection'] ?? '');
$decoded = json_decode((string)($_['urlsJson'] ?? '{}'), true);
$pages = is_array($decoded) ? ($decoded['pages'] ?? []) : [];
$offersUrl = (string)($pages['offers'] ?? '#');
$trendsUrl = (string)($pages['trends'] ?? '#');
$settingsUrl = (string)($pages['settings'] ?? '#');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
/** @var list<array{slug: string, url: string, navLabel: string, active: bool}> $settingsNav */
$settingsNav = is_array($_['settingsNav'] ?? null) ? $_['settingsNav'] : [];
?>
<div id="einkaufcheck-app" class="einkaufcheck-app">
<a class="ekc-skip-link ekc-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
<nav id="app-navigation" class="ekc-nav" role="navigation" aria-label="<?php p($l->t('EinkaufCheck navigation')); ?>">
	<div class="ekc-brand">
		<span class="ekc-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('shopping-cart', 'ekc-brand__icon-svg')); ?>
		</span>
		<div class="ekc-brand__text">
			<p class="ekc-brand__title"><?php p($l->t('EinkaufCheck')); ?></p>
			<p class="ekc-brand__subtitle"><?php p($l->t('Own-brand offers and shopping list')); ?></p>
			<span class="ekc-badge"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<div class="ekc-nav__body">
		<ul class="ekc-nav__list">
			<li class="ekc-nav__item<?php if ($pageId === 'offers') { p(' is-active'); } ?>">
				<a class="ekc-nav__link<?php if ($pageId === 'offers') { p(' is-active'); } ?>"
					href="<?php p($offersUrl); ?>"
					<?php if ($pageId === 'offers'): ?>aria-current="page"<?php endif; ?>>
					<span class="ekc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('shopping-cart')); ?></span>
					<span class="ekc-nav__label"><?php p($l->t('Offers')); ?></span>
				</a>
			</li>
			<li class="ekc-nav__item<?php if ($pageId === 'trends') { p(' is-active'); } ?>">
				<a class="ekc-nav__link<?php if ($pageId === 'trends') { p(' is-active'); } ?>"
					href="<?php p($trendsUrl); ?>"
					<?php if ($pageId === 'trends'): ?>aria-current="page"<?php endif; ?>>
					<span class="ekc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('trending-down')); ?></span>
					<span class="ekc-nav__label"><?php p($l->t('Trends')); ?></span>
				</a>
			</li>
			<li class="ekc-nav__item<?php if ($pageId === 'settings') { p(' is-active'); } ?>">
				<a class="ekc-nav__link<?php if ($pageId === 'settings') { p(' is-active'); } ?>"
					href="<?php p($settingsUrl); ?>"
					<?php if ($pageId === 'settings' && $settingsSection === 'general'): ?>aria-current="page"<?php endif; ?>>
					<span class="ekc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('settings')); ?></span>
					<span class="ekc-nav__label"><?php p($l->t('Settings')); ?></span>
				</a>
				<ul class="ekc-nav__sub">
					<?php foreach ($settingsNav as $item): ?>
						<li>
							<a class="ekc-nav__sublink<?php if (!empty($item['active'])) { p(' is-active'); } ?>"
								href="<?php p((string)$item['url']); ?>"
								<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
								<?php p((string)$item['navLabel']); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</li>
		</ul>
	</div>
	<?php
	$appFeedbackCssPrefix = 'ekc';
	$appFeedbackVersion = (string)($_['appVersion'] ?? '');
	require __DIR__ . '/../parts/feedback-nav-footer.php';
	?>
</nav>
