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
$workspace = is_array($_['workspace'] ?? null) ? $_['workspace'] : null;
$workspaceName = is_array($workspace) ? (string)($workspace['name'] ?? '') : '';
$workspacePrivacy = is_array($workspace) ? (string)($workspace['privacyMode'] ?? 'private') : 'private';
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
	<div class="ekc-workspace-switcher" data-ekc-workspace-switcher>
		<label class="ekc-workspace-switcher__label" for="ekc-workspace-select"><?php p($l->t('Shopping space')); ?></label>
		<select id="ekc-workspace-select" class="ekc-input ekc-workspace-switcher__select" data-ekc-workspace-select aria-describedby="ekc-workspace-switcher-hint">
			<?php if ($workspaceName !== ''): ?>
				<option value="<?php p((string)(int)($workspace['id'] ?? 0)); ?>" selected><?php p($workspaceName); ?></option>
			<?php endif; ?>
		</select>
		<p id="ekc-workspace-switcher-hint" class="ekc-workspace-switcher__hint">
			<?php if ($workspacePrivacy === 'private'): ?>
				<span class="ekc-badge ekc-badge--private"><?php p($l->t('Private')); ?></span>
				<?php p($l->t('Only invited people can see this list.')); ?>
			<?php else: ?>
				<span class="ekc-badge ekc-badge--neutral"><?php p($l->t('Shared')); ?></span>
				<?php p($l->t('Invited people and groups can see this list.')); ?>
			<?php endif; ?>
		</p>
		<button type="button" class="button ekc-workspace-switcher__new" data-ekc-workspace-create>
			<?php p($l->t('New private list')); ?>
		</button>
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
