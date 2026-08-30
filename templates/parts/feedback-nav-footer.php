<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var \OCP\IL10N $l
 * @var string $appFeedbackVersion
 */

use OCA\EinkaufCheck\Service\IconCatalog;

$version = isset($appFeedbackVersion) && is_string($appFeedbackVersion) ? $appFeedbackVersion : '';
$pageUrl = '';
if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
	$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$pageUrl = is_string($path) ? $path : '';
}
$ncVersion = '';
try {
	$ncVersion = (string)\OCP\Server::get(\OCP\IConfig::class)->getSystemValue('version', '');
} catch (\Throwable) {
	$ncVersion = '';
}
$lang = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$isDe = str_starts_with(strtolower($lang), 'de');
$subjectProblem = rawurlencode($isDe ? 'EinkaufCheck: Fehlermeldung' : 'EinkaufCheck: Problem report');
$subjectIdea = rawurlencode($isDe ? 'EinkaufCheck: Feedback' : 'EinkaufCheck: Feedback');
$diag = implode("\n", [
	'App: einkaufcheck ' . $version,
	'Nextcloud: ' . $ncVersion,
	'Page: ' . $pageUrl,
	'Locale: ' . $lang,
	'UTC: ' . gmdate('c'),
]);
$body = rawurlencode(
	($isDe
		? "Bitte beschreiben Sie das Problem.\n\n---\nDieser Kanal ist ohne Antwort-SLA (best effort).\n\n"
		: "Please describe the problem.\n\n---\nThis channel is best-effort, no SLA.\n\n")
	. $diag
);
$mailProblem = 'mailto:dev@software-by-design.de?subject=' . $subjectProblem . '&body=' . $body;
$mailIdea = 'mailto:dev@software-by-design.de?subject=' . $subjectIdea . '&body=' . $body;
$github = 'https://github.com/aSoftwareByDesignRepository/nextcloud-einkaufcheck/issues';
?>
<nav class="ekc-nav-footer" aria-label="<?php p($l->t('Help')); ?>">
	<details class="ekc-help">
		<summary class="ekc-help__summary">
			<span class="ekc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('help')); ?></span>
			<?php p($l->t('Help')); ?>
		</summary>
		<ul class="ekc-help__menu">
			<li>
				<a href="<?php p($mailProblem); ?>">
					<?php p($l->t('Report a problem')); ?>
				</a>
			</li>
			<li>
				<a href="<?php p($mailIdea); ?>">
					<?php p($l->t('Suggest an improvement')); ?>
				</a>
			</li>
			<li>
				<a href="<?php p($github); ?>" target="_blank" rel="noopener noreferrer">
					<?php p($l->t('Open GitHub Issues')); ?>
					<span class="ekc-sr-only"><?php p($l->t('(opens in a new tab)')); ?></span>
				</a>
			</li>
		</ul>
		<p class="ekc-help__note"><?php p($l->t('Best-effort inbox — no reply SLA.')); ?></p>
	</details>
</nav>
