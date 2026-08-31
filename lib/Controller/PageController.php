<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Controller;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Exception\NotFoundException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\SettingsSectionCatalog;
use OCA\EinkaufCheck\Service\WorkspaceService;
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
		private readonly WorkspaceService $workspaces,
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
		$workspace = $this->resolveWorkspace($uid);
		$canManage = $this->canManageWorkspace($workspace);
		if ($this->settingsCatalog->isManagerOnly($section) && !$canManage) {
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
			$workspace,
		);
	}

	/**
	 * @param array<string, mixed>|null $workspace
	 */
	private function page(
		string $pageId,
		string $title,
		string $hint,
		string $settingsSection = '',
		?array $workspace = null,
	): TemplateResponse {
		if ($pageId === 'settings' && $settingsSection !== '' && !$this->settingsCatalog->isSection($settingsSection)) {
			throw new NotFoundException('Settings section not found.');
		}
		$this->registerAssets($pageId, $settingsSection);
		$uid = $this->requireUid();
		$isAppAdmin = $this->access->isAppAdmin($uid);
		$isSystemAdmin = $uid !== '' && $this->groupManager->isAdmin($uid);
		$timezone = (string)$this->config->getUserValue($uid, 'core', 'timezone', 'UTC');
		if ($timezone === '') {
			$timezone = 'UTC';
		}
		if ($workspace === null) {
			$workspace = $this->resolveWorkspace($uid);
		}
		$canManageWorkspace = $this->canManageWorkspace($workspace);
		$workspaceRole = is_array($workspace) ? (string)($workspace['role'] ?? '') : '';
		$u = $this->urlGenerator;
		$pageUrls = [
			'offers' => $u->linkToRoute('einkaufcheck.page.index'),
			'trends' => $u->linkToRoute('einkaufcheck.page.trends'),
			'settings' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'general']),
			'settingsWorkspace' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'workspace']),
			'settingsMembers' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'members']),
			'settingsStores' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'stores']),
			'settingsAccess' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'access']),
		];
		$settingsNav = [];
		foreach ($this->settingsCatalog->visibleSections($isAppAdmin, $canManageWorkspace) as $slug) {
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
			'workspaces' => $u->linkToRoute('einkaufcheck.api.workspacesList'),
			'workspacesCreate' => $u->linkToRoute('einkaufcheck.api.workspacesCreate'),
			'workspaceGetBase' => $u->linkToRoute('einkaufcheck.api.workspaceGet', ['id' => 0]),
			'workspaceUpdateBase' => $u->linkToRoute('einkaufcheck.api.workspaceUpdate', ['id' => 0]),
			'workspaceDeleteBase' => $u->linkToRoute('einkaufcheck.api.workspaceDelete', ['id' => 0]),
			'settingsWorkspace' => $u->linkToRoute('einkaufcheck.page.settings', ['section' => 'workspace']),
			'appIndex' => $u->linkToRoute('einkaufcheck.page.index'),
			'workspaceMembersBase' => $u->linkToRoute('einkaufcheck.api.workspaceMembers', ['id' => 0]),
			'workspaceAddMemberBase' => $u->linkToRoute('einkaufcheck.api.workspaceAddMember', ['id' => 0]),
			'memberUpdateBase' => $u->linkToRoute('einkaufcheck.api.memberUpdate', ['id' => 0]),
			'memberDeleteBase' => $u->linkToRoute('einkaufcheck.api.memberDelete', ['id' => 0]),
			'workspaceAddGroupMemberBase' => $u->linkToRoute('einkaufcheck.api.workspaceAddGroupMember', ['id' => 0]),
			'groupMemberUpdateBase' => $u->linkToRoute('einkaufcheck.api.groupMemberUpdate', ['id' => 0]),
			'groupMemberDeleteBase' => $u->linkToRoute('einkaufcheck.api.groupMemberDelete', ['id' => 0]),
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
		$roleLabel = match ($workspaceRole) {
			AccessControlService::ROLE_MANAGER => $this->l10n->t('Manager'),
			AccessControlService::ROLE_CONTRIBUTOR => $this->l10n->t('Contributor'),
			AccessControlService::ROLE_VIEWER => $this->l10n->t('Viewer'),
			default => $isAppAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member'),
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
			'roleLabel' => $roleLabel,
			'workspace' => $workspace,
			'workspaceJson' => json_encode($workspace ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}',
			'canManageWorkspace' => $canManageWorkspace,
			'canManagePrivacy' => !empty($workspace['capabilities']['canManagePrivacy']),
			'canDeleteWorkspace' => !empty($workspace['capabilities']['canDelete']),
			'storesStatus' => [],
		];
		if ($pageId === 'settings' && $settingsSection === 'stores') {
			$params['storesStatus'] = $this->offers->storesStatus();
		}
		return new TemplateResponse(Application::APP_ID, $template, $params);
	}

	private function registerAssets(string $pageId, string $settingsSection): void {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/messaging');
		Util::addScript(Application::APP_ID, 'common/workspace');
		Util::addScript(Application::APP_ID, 'app');
		if ($pageId === 'settings' && in_array($settingsSection, ['workspace', 'members'], true)) {
			Util::addScript(Application::APP_ID, 'settings-workspace');
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function resolveWorkspace(string $uid): ?array {
		if ($uid === '') {
			return null;
		}
		try {
			$raw = $this->request->getParam('workspaceId');
			$wid = (int)($raw ?? 0);
			if ($wid > 0) {
				return $this->workspaces->getForUser($wid, $uid);
			}
			$last = $this->access->lastUsedWorkspace($uid);
			if ($last !== null && $this->access->role($last, $uid) !== null) {
				return $this->workspaces->getForUser($last, $uid);
			}
			return $this->workspaces->ensurePersonalWorkspace($uid);
		} catch (AccessDeniedException) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed>|null $workspace
	 */
	private function canManageWorkspace(?array $workspace): bool {
		if ($workspace === null) {
			return false;
		}
		$caps = is_array($workspace['capabilities'] ?? null) ? $workspace['capabilities'] : [];
		return !empty($caps['canManageSettings'])
			|| (string)($workspace['role'] ?? '') === AccessControlService::ROLE_MANAGER;
	}

	private function requireUid(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}
}
