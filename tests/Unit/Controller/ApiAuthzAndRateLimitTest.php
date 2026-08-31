<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Controller;

use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use OCA\EinkaufCheck\Service\WorkspaceService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ApiAuthzAndRateLimitTest extends TestCase {
	public function testAccessGetRejectedForNonAdmin(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$access->expects($this->once())->method('assertAppAdmin')->with('victim')
			->willThrowException(new AppAccessDeniedException('App administrator required.'));

		$controller = $this->controller('victim', $access, $this->createMock(RateLimitService::class));
		$this->expectException(AppAccessDeniedException::class);
		$controller->accessGet();
	}

	public function testAccessSaveRejectedForNonAdmin(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$access->expects($this->once())->method('assertAppAdmin')->with('victim')
			->willThrowException(new AppAccessDeniedException('App administrator required.'));

		$controller = $this->controller('victim', $access, $this->createMock(RateLimitService::class));
		$this->expectException(AppAccessDeniedException::class);
		$controller->accessSave();
	}

	public function testDirectoryRejectedForNonManagerNonAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('victim');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$access->method('isAppAdmin')->with('victim')->willReturn(false);
		$access->method('lastUsedWorkspace')->willReturn(1);
		$access->method('role')->willReturn(AccessControlService::ROLE_VIEWER);
		$access->expects($this->once())->method('ensureMinimumRole')
			->with(1, 'victim', AccessControlService::ROLE_MANAGER)
			->willThrowException(new AccessDeniedException());

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn($this->defaultWorkspace());

		$controller = new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(OfferFetchService::class),
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$this->createMock(RateLimitService::class),
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
			$workspaces,
		);
		$this->expectException(AccessDeniedException::class);
		$controller->directoryUsers();
	}

	public function testListExportIsRateLimited(): void {
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('assertAllowed')->with('alice', 'list_export', 30, 3600);
		$list = $this->createMock(ShoppingListService::class);
		$list->expects($this->once())->method('export')->with(1, 'alice', '')->willReturn([
			'text' => 'Einkaufszettel',
			'whatsapp_url' => 'https://wa.me/?text=',
			'csv' => "store;brand;name\n",
			'items' => [],
		]);

		$controller = $this->controller(
			'alice',
			$this->createMock(AccessControlService::class),
			$rl,
			$list,
		);
		$controller->listExport();
	}

	public function testListClearForwardsStoreFilterAndIsRateLimited(): void {
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('assertAllowed')->with('alice', 'list_write', 120, 3600);
		$list = $this->createMock(ShoppingListService::class);
		$list->expects($this->once())->method('clear')->with(1, 'alice', 'Lidl');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return $key === 'store' ? 'Lidl' : $default;
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$this->wireWorkspaceDefaults($workspaces, $access);

		$controller = new ApiController(
			$request,
			$this->createMock(OfferFetchService::class),
			$list,
			$this->createMock(WatchService::class),
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
			$workspaces,
		);
		$controller->listClear();
	}

	public function testTrendsReadIsRateLimitedAndDoesNotFetch(): void {
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('assertAllowed')->with('alice', 'trends_read', 60, 3600);
		$offers = $this->createMock(OfferFetchService::class);
		$offers->expects($this->never())->method('fetch');
		$offers->method('peekCache')->willReturn(null);
		$history = $this->createMock(PriceHistoryService::class);
		$history->method('summarize')->willReturn([
			'staples' => [],
			'cheap_now' => [],
			'search' => [],
			'weeks_tracked' => 0,
			'cache' => 'empty',
		]);
		$watch = $this->createMock(WatchService::class);
		$watch->method('list')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return $default;
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$this->wireWorkspaceDefaults($workspaces, $access);

		$controller = new ApiController(
			$request,
			$offers,
			$this->createMock(ShoppingListService::class),
			$watch,
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$history,
			$this->createMock(WeekCompareService::class),
			$workspaces,
		);
		$controller->trends();
	}

	public function testListGetIsRateLimited(): void {
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('assertAllowed')->with('alice', 'list_read', 120, 3600);
		$list = $this->createMock(ShoppingListService::class);
		$list->expects($this->once())->method('list')->with(1, 'alice')->willReturn([]);
		$this->controller('alice', $this->createMock(AccessControlService::class), $rl, $list)->listGet();
	}

	public function testWatchGetIsRateLimited(): void {
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('assertAllowed')->with('alice', 'watch_read', 120, 3600);
		$watch = $this->createMock(WatchService::class);
		$watch->expects($this->once())->method('list')->with(1, 'alice')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$workspaces = $this->createMock(WorkspaceService::class);
		$this->wireWorkspaceDefaults($workspaces, $access);

		$controller = new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(OfferFetchService::class),
			$this->createMock(ShoppingListService::class),
			$watch,
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
			$workspaces,
		);
		$controller->watchGet();
	}

	public function testSettingsGetAndStoresStatusAreRateLimited(): void {
		$calls = [];
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->exactly(2))->method('assertAllowed')
			->willReturnCallback(static function (string $uid, string $action, int $limit, int $window) use (&$calls): void {
				$calls[] = [$uid, $action, $limit, $window];
			});
		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('storesStatus')->willReturn(['ALDI Nord' => ['ok' => true]]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$workspaces = $this->createMock(WorkspaceService::class);
		$this->wireWorkspaceDefaults($workspaces, $access);

		$controller = new ApiController(
			$this->createMock(IRequest::class),
			$offers,
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
			$workspaces,
		);
		$controller->settingsGet();
		$controller->storesStatus();
		self::assertSame(
			[
				['alice', 'settings_read', 60, 3600],
				['alice', 'stores_read', 60, 3600],
			],
			$calls,
		);
	}

	public function testAccessGetIsRateLimitedForAdmin(): void {
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('assertAllowed')->with('admin', 'access_read', 60, 3600);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$access->expects($this->once())->method('assertAppAdmin')->with('admin');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getAll')->willReturn([
			'access_mode' => 'open',
			'access_groups' => [],
			'access_users' => [],
			'app_admins' => [],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$workspaces = $this->createMock(WorkspaceService::class);
		$this->wireWorkspaceDefaults($workspaces, $access);

		$controller = new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(OfferFetchService::class),
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$rl,
			$access,
			$settings,
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
			$workspaces,
		);
		$controller->accessGet();
	}

	private function controller(
		string $uid,
		AccessControlService $access,
		RateLimitService $rl,
		?ShoppingListService $list = null,
		?WorkspaceService $workspaces = null,
	): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access->method('assertCanUseApp');

		$ws = $workspaces ?? $this->createMock(WorkspaceService::class);
		$this->wireWorkspaceDefaults($ws, $access);

		return new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(OfferFetchService::class),
			$list ?? $this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
			$ws,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function defaultWorkspace(): array {
		return [
			'id' => 1,
			'plz' => '24149',
			'week' => 'current',
			'showImages' => false,
			'role' => AccessControlService::ROLE_MANAGER,
			'capabilities' => [
				'canEditList' => true,
				'canManageSettings' => true,
			],
		];
	}

	private function wireWorkspaceDefaults(WorkspaceService $workspaces, AccessControlService $access): void {
		$ws = $this->defaultWorkspace();
		$workspaces->method('ensurePersonalWorkspace')->willReturn($ws);
		$workspaces->method('getForUser')->willReturn($ws);
		$workspaces->method('listForUser')->willReturn([$ws]);
		$workspaces->method('getPrefs')->willReturn([
			'plz' => '24149',
			'week' => 'current',
			'show_images' => false,
		]);
		$access->method('lastUsedWorkspace')->willReturn(1);
		$access->method('role')->willReturn(AccessControlService::ROLE_MANAGER);
		$access->method('ensureMinimumRole')->willReturn(AccessControlService::ROLE_MANAGER);
	}
}
