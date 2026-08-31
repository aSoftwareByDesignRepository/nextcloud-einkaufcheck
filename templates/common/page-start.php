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
$pageTitle = (string)($_['pageTitle'] ?? '');
$pageHint = (string)($_['pageHint'] ?? '');
$currentUserId = (string)($_['currentUserId'] ?? '');
$isAppAdmin = !empty($_['isAppAdmin']);
$timezone = (string)($_['timezone'] ?? 'UTC');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$urlsJson = (string)($_['urlsJson'] ?? '{}');
$settingsSection = (string)($_['settingsSection'] ?? '');
$headerIcon = (string)($_['headerIcon'] ?? ($pageId === 'settings' ? 'settings' : 'shopping-cart'));
$settingsNav = is_array($_['settingsNav'] ?? null) ? $_['settingsNav'] : [];
$workspaceJson = (string)($_['workspaceJson'] ?? '{}');
$workspace = is_array($_['workspace'] ?? null) ? $_['workspace'] : null;
$workspaceId = is_array($workspace) ? (int)($workspace['id'] ?? 0) : 0;
$canEditList = !empty($workspace['capabilities']['canEditList']);
$canManageSettings = !empty($workspace['capabilities']['canManageSettings']);
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$decodedUrls = json_decode($urlsJson, true);
$navUrls = is_array($decodedUrls) ? ($decodedUrls['pages'] ?? []) : [];
$homeUrl = (string)($navUrls['offers'] ?? '#');
$settingsHomeUrl = (string)($navUrls['settings'] ?? '#');

require __DIR__ . '/navigation.php';
?>
<div id="app-content"
	class="ekc-app ekc-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-ekc-page="<?php p($pageId); ?>"
	<?php if ($settingsSection !== ''): ?>data-ekc-settings-section="<?php p($settingsSection); ?>"<?php endif; ?>
	data-ekc-current-user="<?php p($currentUserId); ?>"
	data-ekc-is-app-admin="<?php p($isAppAdmin ? '1' : '0'); ?>"
	data-ekc-timezone="<?php p($timezone); ?>"
	data-ekc-workspace-id="<?php p((string)$workspaceId); ?>"
	data-ekc-can-edit-list="<?php p($canEditList ? '1' : '0'); ?>"
	data-ekc-can-manage-settings="<?php p($canManageSettings ? '1' : '0'); ?>"
	data-ekc-workspace="<?php p($workspaceJson); ?>"
	data-ekc-urls="<?php p($urlsJson); ?>">
	<a class="ekc-skip-link" href="#ekc-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="ekc-live-region" class="ekc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="ekc-alert-region" class="ekc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="ekc-toast-region" class="ekc-toast-region" role="region" aria-label="<?php p($l->t('Notifications')); ?>"></div>
	<div id="app-content-wrapper" class="ekc-shell">
		<header class="ekc-page-header" aria-labelledby="ekc-page-title">
			<nav class="ekc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol class="ekc-breadcrumb__list">
					<li class="ekc-breadcrumb__item">
						<a class="ekc-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($l->t('EinkaufCheck')); ?></a>
					</li>
					<?php if ($pageId === 'settings'): ?>
						<li class="ekc-breadcrumb__item">
							<a class="ekc-breadcrumb__link" href="<?php p($settingsHomeUrl); ?>"><?php p($l->t('Settings')); ?></a>
						</li>
						<li class="ekc-breadcrumb__item ekc-breadcrumb__item--current" aria-current="page">
							<span class="ekc-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					<?php else: ?>
						<li class="ekc-breadcrumb__item ekc-breadcrumb__item--current" aria-current="page">
							<span class="ekc-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					<?php endif; ?>
				</ol>
			</nav>
			<div class="ekc-page-header__main">
				<div class="ekc-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIcon, 'ekc-page-header__icon-svg')); ?>
				</div>
				<div class="ekc-page-header__text">
					<h1 id="ekc-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHint !== ''): ?>
						<p class="ekc-page-header__lead"><?php p($pageHint); ?></p>
					<?php endif; ?>
				</div>
				<div id="ekc-page-actions" class="ekc-page-header__actions"></div>
			</div>
			<div class="ekc-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="ekc-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="ekc-badge ekc-badge--neutral ekc-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="ekc-scope-strip__sep" aria-hidden="true">·</span>
				<span class="ekc-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="ekc-scope-strip__value"><?php p($timezone); ?></span>
			</div>
		</header>
		<main id="ekc-main-content" class="ekc-main" tabindex="-1" aria-labelledby="ekc-page-title">
			<div class="ekc-page-stack">
