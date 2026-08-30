<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\EinkaufCheck\Service\SettingsSectionCatalog;

$section = (string)($_['settingsSection'] ?? SettingsSectionCatalog::DEFAULT_SECTION);
$catalog = new SettingsSectionCatalog();
if (!$catalog->isSection($section)) {
	$section = SettingsSectionCatalog::DEFAULT_SECTION;
}
/** @var list<array{slug: string, url: string, navLabel: string, active: bool}> $settingsNav */
$settingsNav = is_array($_['settingsNav'] ?? null) ? $_['settingsNav'] : [];

require __DIR__ . '/common/page-start.php';
?>

<nav class="ekc-settings-chips" aria-label="<?php p($l->t('Settings topics')); ?>">
	<?php foreach ($settingsNav as $item): ?>
		<a class="ekc-chip<?php if (!empty($item['active'])) { p(' is-active'); } ?>"
			href="<?php p((string)$item['url']); ?>"
			<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
			<?php p((string)$item['navLabel']); ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php
// Catalog allowlist — never concatenate raw request into include paths.
require __DIR__ . '/' . $catalog->templatePart($section) . '.php';
require __DIR__ . '/common/page-end.php';
