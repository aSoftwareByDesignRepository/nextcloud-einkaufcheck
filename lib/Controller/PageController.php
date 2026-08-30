<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Controller;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\NotFoundException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\SettingsSectionCatalog;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly SettingsSectionCatalog $settingsCatalog,
		private readonly OfferFetchService $offers,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return $this->page(
			'offers',
			$this->l10n->t('Offers'),
			$this->l10n->t('Find this week’s own-brand deals, add them to your list, and get a ping when staples go on sale.'),
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function trends(): TemplateResponse {
		return $this->page(
			'trends',
			$this->l10n->t('When is it cheaper?'),
			$this->l10n->t('We compare this week’s price with earlier weeks. Cheaper than usual is written in words, not only in colour.'),
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsIndex(): TemplateResponse|RedirectResponse {
		return $this->settings(SettingsSectionCatalog::DEFAULT_SECTION);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section = SettingsSectionCatalog::DEFAULT_SECTION): TemplateResponse|RedirectResponse {
		$uid = $this->requireUid();
		$isAppAdmin = $this->access->isAppAdmin($uid);
		if (!$this->settingsCatalog->isSection($section)) {
			throw new NotFoundException('Settings section not found.');
		}
		if ($this->settingsCatalog->isAdminOnly($section) && !$isAppAdmin) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute(
					'einkaufcheck.page.settings',
					['section' => SettingsSectionCatalog::DEFAULT_SECTION],
				),
			);
		}
		return $this->page(
			'settings',
			$this->settingsCatalog->label($this->l10n, $section),
			$this->settingsCatalog->help($this->l10n, $section),
			$section,
		);
	}

	private function page(string $pageId, string $title, string $hint, string $settingsSection = ''): TemplateResponse {
		if ($pageId === 'settings' && $settingsSection !== '' && !$this->settingsCatalog->isSection($settingsSection)) {
			throw new NotFoundException('Settings section not found.');
		}
		$this->registerAssets();
		$uid = $this->requireUid();
		$isAppAdmin = $this->access->isAppAdmin($uid);
		$isSystemAdmin = $uid !== '' && $this->groupManager->isAdmin($uid);
		$timezone = (string)$this->config->getUserValue($uid, 'core', 'timezone', 'UTC');
		if ($timezone === '') {
			$timezone = 'UTC';
		}
		$u = $this->urlGenerator;
		$pageUrls = [
			'offers' => $u->linkToRoute('einkaufcheck.page.index'),
			'trends' => $u->linkToRoute('einkaufcheck.page.trends'),
			'settings' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'general']),
			'settingsStores' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'stores']),
			'settingsAccess' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'access']),
		];
		$settingsNav = [];
		foreach ($this->settingsCatalog->visibleSections($isAppAdmin) as $slug) {
			$settingsNav[] = [
				'slug' => $slug,
				'url' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => $slug]),
				'navLabel' => $this->settingsCatalog->navLabel($this->l10n, $slug),
				'active' => $pageId === 'settings' && $settingsSection === $slug,
			];
		}
		$urls = [
			'offers' => $u->linkToRoute('einkaufcheck.api.offers'),
			'offersRefresh' => $u->linkToRoute('einkaufcheck.api.offersRefresh'),
			'list' => $u->linkToRoute('einkaufcheck.api.listGet'),
			'listAdd' => $u->linkToRoute('einkaufcheck.api.listAdd'),
			'listClear' => $u->linkToRoute('einkaufcheck.api.listClear'),
			'listExport' => $u->linkToRoute('einkaufcheck.api.listExport'),
			'settings' => $u->linkToRoute('einkaufcheck.api.settingsGet'),
			'settingsSave' => $u->linkToRoute('einkaufcheck.api.settingsSave'),
			'stores' => $u->linkToRoute('einkaufcheck.api.storesStatus'),
			'listUpdateBase' => $u->linkToRoute('einkaufcheck.api.listUpdate', ['id' => 0]),
			'listDeleteBase' => $u->linkToRoute('einkaufcheck.api.listDelete', ['id' => 0]),
			'watch' => $u->linkToRoute('einkaufcheck.api.watchGet'),
			'watchAdd' => $u->linkToRoute('einkaufcheck.api.watchAdd'),
			'watchUpdateBase' => $u->linkToRoute('einkaufcheck.api.watchUpdate', ['id' => 0]),
			'watchDeleteBase' => $u->linkToRoute('einkaufcheck.api.watchDelete', ['id' => 0]),
			'watchHits' => $u->linkToRoute('einkaufcheck.api.watchHits'),
			'trends' => $u->linkToRoute('einkaufcheck.api.trends'),
			'accessGet' => $u->linkToRoute('einkaufcheck.api.accessGet'),
			'accessSave' => $u->linkToRoute('einkaufcheck.api.accessSave'),
			'directoryUsers' => $u->linkToRoute('einkaufcheck.api.directoryUsers'),
			'directoryGroups' => $u->linkToRoute('einkaufcheck.api.directoryGroups'),
			'pages' => $pageUrls,
		];
		$template = match ($pageId) {
			'settings' => 'settings',
			'trends' => 'trends',
			default => 'main',
		};
		$headerIcon = match ($pageId) {
			'settings' => $this->settingsCatalog->headerIcon($settingsSection),
			'trends' => 'trending-down',
			default => 'shopping-cart',
		};
		$params = [
			'pageId' => $pageId,
			'pageTitle' => $title,
			'pageHint' => $hint,
			'settingsSection' => $settingsSection,
			'settingsNav' => $settingsNav,
			'headerIcon' => $headerIcon,
			'currentUserId' => $uid,
			'isAppAdmin' => $isAppAdmin,
			'isSystemAdmin' => $isSystemAdmin,
			'timezone' => $timezone,
			'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
			'urls' => $urls,
			'urlsJson' => json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}',
			'roleLabel' => $isAppAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member'),
			'storesStatus' => [],
		];
		if ($pageId === 'settings' && $settingsSection === 'stores') {
			$params['storesStatus'] = $this->offers->storesStatus();
		}
		return new TemplateResponse(Application::APP_ID, $template, $params);
	}

	private function registerAssets(): void {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/messaging');
		Util::addScript(Application::APP_ID, 'app');
	}

	private function requireUid(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}
}
