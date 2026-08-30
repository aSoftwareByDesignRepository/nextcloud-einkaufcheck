<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Controller;

use OCA\EinkaufCheck\AppInfo\Application;
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
	public function offers(): JSONResponse {
		$uid = $this->uid();
		$prefs = $this->offers->getUserPrefs($uid);
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
		$data['watch_hits'] = $this->watch->hitsForUser($uid, $offerList);
		return new JSONResponse($data);
	}

	#[NoAdminRequired]
	public function offersRefresh(): JSONResponse {
		$uid = $this->uid();
		$prefs = $this->offers->getUserPrefs($uid);
		$plz = (string)$this->request->getParam('plz', $prefs['plz']);
		$week = (string)$this->request->getParam('week', $prefs['week']);
		$this->rateLimit->assertAllowed($uid, 'offers_refresh', 4, 3600);
		$this->rateLimit->assertAllowed($uid, 'offers_fetch', 20, 3600);
		$this->offers->saveUserPrefs($uid, $plz, $week);
		$data = $this->offers->fetch($plz, $week, true);
		$data = $this->withWeekTips($data, $plz, $week);
		$offerList = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		$data['watch_hits'] = $this->watch->hitsForUser($uid, $offerList);
		return new JSONResponse($data);
	}

	/**
	 * Always attach unit_price (even for older cache rows) and compare to the
	 * other week via peekCache only — never live-fetch on GET.
	 *
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
		$this->rateLimit->assertAllowed($uid, 'settings_read', 60, 3600);
		return new JSONResponse($this->offers->getUserPrefs($uid));
	}

	#[NoAdminRequired]
	public function settingsSave(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'settings_write', 30, 3600);
		$body = $this->body();
		$plz = (string)($body['plz'] ?? '');
		$week = (string)($body['week'] ?? 'current');
		$showImages = null;
		if (array_key_exists('show_images', $body)) {
			$showImages = InputCoercion::asBool($body['show_images'], 'show_images');
		}
		return new JSONResponse($this->offers->saveUserPrefs($uid, $plz, $week, $showImages));
	}

	#[NoAdminRequired]
	public function listGet(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'list_read', 120, 3600);
		return new JSONResponse(['items' => $this->list->list($uid)]);
	}

	#[NoAdminRequired]
	public function listAdd(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		$item = $this->list->add($uid, $this->body());
		return new JSONResponse($item, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function listUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		return new JSONResponse($this->list->update($uid, $id, $this->body()));
	}

	#[NoAdminRequired]
	public function listDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		$this->list->delete($uid, $id);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function listClear(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'list_write', 120, 3600);
		$store = (string)($this->request->getParam('store', '') ?? '');
		$this->list->clear($uid, $store);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function listExport(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'list_export', 30, 3600);
		$store = (string)($this->request->getParam('store', '') ?? '');
		return new JSONResponse($this->list->export($uid, $store));
	}

	#[NoAdminRequired]
	public function watchGet(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'watch_read', 120, 3600);
		return new JSONResponse(['items' => $this->watch->list($uid)]);
	}

	#[NoAdminRequired]
	public function watchAdd(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'watch_write', 60, 3600);
		$item = $this->watch->add($uid, $this->body());
		return new JSONResponse($item, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function watchUpdate(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'watch_write', 60, 3600);
		return new JSONResponse($this->watch->update($uid, $id, $this->body()));
	}

	#[NoAdminRequired]
	public function watchDelete(int $id): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'watch_write', 60, 3600);
		$this->watch->delete($uid, $id);
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
		$this->accessControl->assertAppAdmin($uid);
		$this->rateLimit->assertAllowed($uid, 'directory_search', 60, 60);
		$q = (string)$this->request->getParam('q', '');
		return new JSONResponse(['items' => $this->directory->searchUsers($q)]);
	}

	#[NoAdminRequired]
	public function directoryGroups(): JSONResponse {
		$uid = $this->uid();
		$this->accessControl->assertAppAdmin($uid);
		$this->rateLimit->assertAllowed($uid, 'directory_search', 60, 60);
		$q = (string)$this->request->getParam('q', '');
		return new JSONResponse(['items' => $this->directory->searchGroups($q)]);
	}

	#[NoAdminRequired]
	public function watchHits(): JSONResponse {
		$uid = $this->uid();
		$this->rateLimit->assertAllowed($uid, 'watch_hits', 60, 3600);
		$prefs = $this->offers->getUserPrefs($uid);
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
		return new JSONResponse(['hits' => $this->watch->hitsForUser($uid, $offerList)]);
	}

	#[NoAdminRequired]
	public function trends(): JSONResponse {
		$uid = $this->uid();
		$prefs = $this->offers->getUserPrefs($uid);
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
		$watches = $this->watch->list($uid, true);
		$data = $this->history->summarize($plz, $week, $offerList, $watches, $query, $store);
		$data['cache'] = $cache;
		return new JSONResponse($data);
	}
}
