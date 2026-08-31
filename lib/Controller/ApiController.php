<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Controller;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCA\EinkaufCheck\Service\InputCoercion;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\OfferUnitPrice;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use OCA\EinkaufCheck\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly OfferFetchService $offers,
		private readonly ShoppingListService $list,
		private readonly WatchService $watch,
		private readonly IUserSession $userSession,
		private readonly RateLimitService $rateLimit,
		private readonly AccessControlService $accessControl,
		private readonly SettingsService $appSettings,
		private readonly DirectorySearchService $directory,
		private readonly PriceHistoryService $history,
		private readonly WeekCompareService $weekCompare,
		private readonly WorkspaceService $workspaces,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AppAccessDeniedException('Not logged in.');
		}
		$uid = $user->getUID();
		$this->accessControl->assertCanUseApp($uid);
		return $uid;
	}

	/**
	 * Resolve workspace from query/body, last-used, or auto-provisioned personal space.
	 * Always membership-gates the resolved id (fail closed for ghosts).
	 */
	private function workspaceId(string $uid): int {
		$body = $this->body();
		$raw = $this->request->getParam('workspaceId', null);
		if ($raw === null || $raw === '') {
			$raw = $this->request->getParam('workspace_id', null);
		}
		if (($raw === null || $raw === '') && isset($body['workspaceId'])) {
			$raw = $body['workspaceId'];
		}
		if (($raw === null || $raw === '') && isset($body['workspace_id'])) {
			$raw = $body['workspace_id'];
		}
		$wid = (int)($raw ?? 0);
		if ($wid < 1) {
			$last = $this->accessControl->lastUsedWorkspace($uid);
			if ($last !== null && $this->accessControl->role($last, $uid) !== null) {
				$wid = $last;
			} else {
				$ws = $this->workspaces->ensurePersonalWorkspace($uid);
				$wid = (int)$ws['id'];
			}
		}
		// Opaque membership + existence gate; remembers last-used on success.
		$this->workspaces->getForUser($wid, $uid);
		return $wid;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$params = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if (is_string($raw) && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$params = array_merge($params, $json);
			}
		}
		unset($params['requesttoken']);
		foreach (['access_groups', 'access_users', 'app_admins'] as $listKey) {
			if (!array_key_exists($listKey, $params) || is_array($params[$listKey])) {
				continue;
			}
			if (!is_string($params[$listKey])) {
				continue;
			}
			if ($params[$listKey] === '' || $params[$listKey] === '[]') {
				$params[$listKey] = [];
				continue;
			}
			$decoded = json_decode($params[$listKey], true);
			if (is_array($decoded)) {
				$params[$listKey] = $decoded;
			}
		}
		return $params;
	}

	#[NoAdminRequired]
	public function workspacesList(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'workspaces_read', 60, 3600);
		$list = $this->workspaces->listForUser($uid);
		if ($list === []) {
			$list = [$this->workspaces->ensurePersonalWorkspace($uid)];
		}
		return new JSONResponse([
			'items' => $list,
			'capabilities' => [
				'canCreateWorkspace' => $this->accessControl->canCreateWorkspace($uid, AccessControlService::PRIVACY_PRIVATE)
					|| $this->accessControl->canCreateWorkspace($uid, AccessControlService::PRIVACY_STANDARD),
				'canCreatePrivateWorkspace' => $this->accessControl->canCreateWorkspace($uid, AccessControlService::PRIVACY_PRIVATE),
				'canCreateStandardWorkspace' => $this->accessControl->canCreateWorkspace($uid, AccessControlService::PRIVACY_STANDARD),
			],
		]);
	}

	#[NoAdminRequired]
	public function workspacesCreate(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'workspaces_write', 30, 3600);
		$body = $this->body();
		$name = (string)($body['name'] ?? 'My shopping list');
		$privacy = (string)($body['privacyMode'] ?? $body['privacy_mode'] ?? AccessControlService::PRIVACY_PRIVATE);
		$ws = $this->workspaces->createWorkspace($uid, $name, $privacy);
		return new JSONResponse($ws, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function workspaceGet(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'workspaces_read', 60, 3600);
		return new JSONResponse($this->workspaces->getForUser($id, $uid));
	}

	#[NoAdminRequired]
	public function workspaceUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'workspaces_write', 30, 3600);
		return new JSONResponse($this->workspaces->updateWorkspace($id, $uid, $this->body()));
	}

	#[NoAdminRequired]
	public function workspaceDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'workspaces_write', 20, 3600);
		return new JSONResponse($this->workspaces->deleteWorkspace($id, $uid));
	}

	#[NoAdminRequired]
	public function workspaceMembers(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_read', 60, 3600);
		return new JSONResponse(['items' => $this->workspaces->listMembers($id, $uid)]);
	}

	#[NoAdminRequired]
	public function workspaceAddMember(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_write', 30, 3600);
		$items = $this->workspaces->addMember($id, $uid, $this->body());
		return new JSONResponse(['items' => $items], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function memberUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_write', 30, 3600);
		return new JSONResponse(['items' => $this->workspaces->updateMember($id, $uid, $this->body())]);
	}

	#[NoAdminRequired]
	public function memberDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_write', 30, 3600);
		return new JSONResponse(['items' => $this->workspaces->removeMember($id, $uid)]);
	}

	#[NoAdminRequired]
	public function workspaceAddGroupMember(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_write', 30, 3600);
		$items = $this->workspaces->addGroupMember($id, $uid, $this->body());
		return new JSONResponse(['items' => $items], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function groupMemberUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_write', 30, 3600);
		return new JSONResponse(['items' => $this->workspaces->updateGroupMember($id, $uid, $this->body())]);
	}

	#[NoAdminRequired]
	public function groupMemberDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'members_write', 30, 3600);
		return new JSONResponse(['items' => $this->workspaces->removeGroupMember($id, $uid)]);
	}

	#[NoAdminRequired]
	public function offers(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$prefs = $this->workspaces->getPrefs($wsId, $uid);
		$plz = (string)$this->request->getParam('plz', $prefs['plz']);
		$week = (string)$this->request->getParam('week', $prefs['week']);
		$this->rateLimit->assertAllowed($uid, 'offers_read', 120, 3600);
		$data = $this->offers->peekCache($plz, $week);
		if ($data === null) {
			throw new ValidationException(
				'Offer cache is empty. Refresh to fetch live prices.',
				[],
				'offers_stale',
			);
		}
		$data = $this->withWeekTips($data, $plz, $week);
		$offerList = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		$data['watch_hits'] = $this->watch->hitsForUser($wsId, $uid, $offerList);
		$data['workspaceId'] = $wsId;
		$data['plz'] = $plz;
		$data['week'] = $week;
		return new JSONResponse($data);
	}

	#[NoAdminRequired]
	public function offersRefresh(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$prefs = $this->workspaces->getPrefs($wsId, $uid);
		$requestedPlz = (string)$this->request->getParam('plz', $prefs['plz']);
		$requestedWeek = (string)$this->request->getParam('week', $prefs['week']);
		$this->rateLimit->assertAllowed($uid, 'offers_refresh', 4, 3600);
		$this->rateLimit->assertAllowed($uid, 'offers_fetch', 20, 3600);
		// Managers may change PLZ/week and persist. Contributors may only refresh
		// the space's saved postcode — otherwise they could probe arbitrary PLZs
		// into the shared offer cache.
		$plz = $prefs['plz'];
		$week = $prefs['week'];
		try {
			$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_MANAGER);
			$plz = $requestedPlz;
			$week = $requestedWeek;
			$this->workspaces->savePrefs($wsId, $uid, $plz, $week);
		} catch (AccessDeniedException) {
			// contributor: bound to saved prefs
		}
		$data = $this->offers->fetch($plz, $week, true);
		$data = $this->withWeekTips($data, $plz, $week);
		$offerList = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		$data['watch_hits'] = $this->watch->hitsForUser($wsId, $uid, $offerList);
		$data['workspaceId'] = $wsId;
		$data['plz'] = $plz;
		$data['week'] = $week;
		return new JSONResponse($data);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function withWeekTips(array $data, string $plz, string $week): array {
		$week = $week === 'next' ? 'next' : 'current';
		$otherWeek = $week === 'next' ? 'current' : 'next';
		$offers = [];
		foreach (is_array($data['offers'] ?? null) ? $data['offers'] : [] as $row) {
			if (!is_array($row)) {
				continue;
			}
			$offers[] = OfferUnitPrice::enrich($row);
		}

		$otherPayload = $this->offers->peekCache($plz, $otherWeek);
		$otherOffers = [];
		$otherCache = 'miss';
		if (is_array($otherPayload)) {
			$otherCache = 'hit';
			foreach (is_array($otherPayload['offers'] ?? null) ? $otherPayload['offers'] : [] as $row) {
				if (is_array($row)) {
					$otherOffers[] = $row;
				}
			}
		}

		if ($otherCache === 'hit') {
			$offers = $this->weekCompare->annotate($offers, $otherOffers, $otherWeek, $plz);
		} else {
			foreach ($offers as $i => $offer) {
				$offers[$i]['week_tip'] = null;
			}
		}

		$data['offers'] = $offers;
		$data['week_compare'] = [
			'other_week' => $otherWeek,
			'other_cache' => $otherCache,
		];
		return $data;
	}

	#[NoAdminRequired]
	public function storesStatus(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'stores_read', 60, 3600);
		return new JSONResponse($this->offers->storesStatus());
	}

	#[NoAdminRequired]
	public function settingsGet(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$this->rateLimit->assertAllowed($uid, 'settings_read', 60, 3600);
		$prefs = $this->workspaces->getPrefs($wsId, $uid);
		$prefs['workspaceId'] = $wsId;
		return new JSONResponse($prefs);
	}

	#[NoAdminRequired]
	public function settingsSave(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_MANAGER);
		$this->rateLimit->assertAllowed($uid, 'settings_write', 30, 3600);
		$body = $this->body();
		$plz = (string)($body['plz'] ?? '');
		$week = (string)($body['week'] ?? 'current');
		$showImages = null;
		if (array_key_exists('show_images', $body)) {
			$showImages = InputCoercion::asBool($body['show_images'], 'show_images');
		}
		$prefs = $this->workspaces->savePrefs($wsId, $uid, $plz, $week, $showImages);
		$prefs['workspaceId'] = $wsId;
		return new JSONResponse($prefs);
	}

	#[NoAdminRequired]
	public function listGet(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$this->rateLimit->assertAllowed($uid, 'list_read', 120, 3600);
		return new JSONResponse(['items' => $this->list->list($wsId, $uid), 'workspaceId' => $wsId]);
	}

	#[NoAdminRequired]
	public function listAdd(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		$item = $this->list->add($wsId, $uid, $this->body());
		return new JSONResponse($item, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function listUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		return new JSONResponse($this->list->update($wsId, $uid, $id, $this->body()));
	}

	#[NoAdminRequired]
	public function listDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		$this->list->delete($wsId, $uid, $id);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function listClear(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		$store = (string)($this->request->getParam('store', '') ?? '');
		$this->list->clear($wsId, $uid, $store);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function listExport(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$this->rateLimit->assertAllowed($uid, 'list_export', 30, 3600);
		$store = (string)($this->request->getParam('store', '') ?? '');
		return new JSONResponse($this->list->export($wsId, $uid, $store));
	}

	#[NoAdminRequired]
	public function watchGet(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$this->rateLimit->assertAllowed($uid, 'watch_read', 120, 3600);
		return new JSONResponse(['items' => $this->watch->list($wsId, $uid), 'workspaceId' => $wsId]);
	}

	#[NoAdminRequired]
	public function watchAdd(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'watch_write', 60, 3600);
		$item = $this->watch->add($wsId, $uid, $this->body());
		return new JSONResponse($item, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function watchUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'watch_write', 60, 3600);
		return new JSONResponse($this->watch->update($wsId, $uid, $id, $this->body()));
	}

	#[NoAdminRequired]
	public function watchDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
		$this->rateLimit->assertAllowed($uid, 'watch_write', 60, 3600);
		$this->watch->delete($wsId, $uid, $id);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function accessGet(): JSONResponse {
		$uid = $this->uid();
		$this->accessControl->assertAppAdmin($uid);
		$this->rateLimit->assertAllowed($uid, 'access_read', 60, 3600);
		return new JSONResponse($this->appSettings->getAll());
	}

	#[NoAdminRequired]
	public function accessSave(): JSONResponse {
		$uid = $this->uid();
		$this->accessControl->assertAppAdmin($uid);
		$this->rateLimit->assertAllowed($uid, 'access_write', 30, 3600);
		$body = $this->body();
		$snapshot = $this->appSettings->snapshotAccess();
		try {
			if (array_key_exists('access_mode', $body)) {
				$this->appSettings->setAccessMode((string)$body['access_mode']);
			}
			if (array_key_exists('access_groups', $body) && is_array($body['access_groups'])) {
				$this->appSettings->setAccessGroups($body['access_groups']);
			}
			if (array_key_exists('access_users', $body) && is_array($body['access_users'])) {
				$this->appSettings->setAccessUsers($body['access_users']);
			}
			if (array_key_exists('app_admins', $body) && is_array($body['app_admins'])) {
				$this->appSettings->setAppAdmins($body['app_admins']);
			}
			if (!$this->accessControl->canUseApp($uid)) {
				throw new ValidationException(
					'That change would lock you out of EinkaufCheck.',
					[],
					'self_lockout',
				);
			}
		} catch (\Throwable $e) {
			$this->appSettings->restoreAccess($snapshot);
			throw $e;
		}
		return new JSONResponse($this->appSettings->getAll());
	}

	#[NoAdminRequired]
	public function directoryUsers(): JSONResponse {
		$uid = $this->uid();
		$this->assertDirectoryUserSearchAllowed($uid);
		$this->rateLimit->assertAllowed($uid, 'directory_search', 60, 60);
		$q = (string)$this->request->getParam('q', '');
		$full = $this->accessControl->isAppAdmin($uid);
		return new JSONResponse([
			'items' => $this->directory->searchUsers($q, $uid, $full),
			'scope' => $full ? 'directory' : 'peer',
		]);
	}

	#[NoAdminRequired]
	public function directoryGroups(): JSONResponse {
		$uid = $this->uid();
		$this->assertDirectoryGroupSearchAllowed($uid);
		$this->rateLimit->assertAllowed($uid, 'directory_search', 60, 60);
		$q = (string)$this->request->getParam('q', '');
		return new JSONResponse(['items' => $this->directory->searchGroups($q), 'scope' => 'directory']);
	}

	private function assertDirectoryUserSearchAllowed(string $uid): void {
		if ($this->accessControl->isAppAdmin($uid)) {
			return;
		}
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_MANAGER);
	}

	/**
	 * Groups are for Standard spaces / Access policy — not private household invites.
	 */
	private function assertDirectoryGroupSearchAllowed(string $uid): void {
		if ($this->accessControl->isAppAdmin($uid)) {
			return;
		}
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_MANAGER);
		$privacy = $this->accessControl->privacyMode($wsId);
		if ($privacy === AccessControlService::PRIVACY_PRIVATE) {
			throw new AccessDeniedException();
		}
		if ($this->accessControl->individualMemberRole($wsId, $uid) !== AccessControlService::ROLE_MANAGER) {
			throw new AccessDeniedException();
		}
	}

	#[NoAdminRequired]
	public function watchHits(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$this->rateLimit->assertAllowed($uid, 'watch_hits', 60, 3600);
		$prefs = $this->workspaces->getPrefs($wsId, $uid);
		$plz = (string)$this->request->getParam('plz', $prefs['plz']);
		$week = (string)$this->request->getParam('week', $prefs['week']);
		$data = $this->offers->peekCache($plz, $week);
		if ($data === null) {
			throw new ValidationException(
				'Offer cache is empty. Refresh to fetch live prices.',
				[],
				'offers_stale',
			);
		}
		$offerList = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		return new JSONResponse(['hits' => $this->watch->hitsForUser($wsId, $uid, $offerList)]);
	}

	#[NoAdminRequired]
	public function trends(): JSONResponse {
		$uid = $this->uid();
		$wsId = $this->workspaceId($uid);
		$this->accessControl->ensureMinimumRole($wsId, $uid, AccessControlService::ROLE_VIEWER);
		$prefs = $this->workspaces->getPrefs($wsId, $uid);
		$plz = (string)$this->request->getParam('plz', $prefs['plz']);
		$week = (string)$this->request->getParam('week', $prefs['week']);
		$query = (string)$this->request->getParam('q', '');
		$store = (string)$this->request->getParam('store', '');
		$this->rateLimit->assertAllowed($uid, 'trends_read', 60, 3600);
		$cached = $this->offers->peekCache($plz, $week);
		$offerList = [];
		$cache = 'empty';
		if (is_array($cached)) {
			$offerList = is_array($cached['offers'] ?? null) ? $cached['offers'] : [];
			$cache = 'hit';
		}
		$watches = $this->watch->list($wsId, $uid, true);
		$data = $this->history->summarize($plz, $week, $offerList, $watches, $query, $store);
		$data['cache'] = $cache;
		$data['workspaceId'] = $wsId;
		return new JSONResponse($data);
	}
}
