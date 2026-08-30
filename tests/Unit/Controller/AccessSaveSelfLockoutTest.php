<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Controller;

use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AccessSaveSelfLockoutTest extends TestCase {
	public function testSaveRollsBackWhenCallerWouldLoseAppAccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('delegate');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$access->method('assertAppAdmin');
		$access->method('canUseApp')->with('delegate')->willReturn(false);

		$snapshot = [
			'access_mode' => SettingsService::MODE_OPEN,
			'access_groups' => [],
			'access_users' => [],
			'app_admins' => ['delegate'],
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('snapshotAccess')->willReturn($snapshot);
		$settings->expects($this->once())->method('setAccessMode')->with(SettingsService::MODE_RESTRICTED);
		$settings->expects($this->once())->method('restoreAccess')->with($snapshot);
		$settings->expects($this->never())->method('getAll');

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'access_mode' => 'restricted',
			'access_groups' => [],
			'access_users' => [],
			'app_admins' => [],
		]);

		$controller = new ApiController(
			$request,
			$this->createMock(OfferFetchService::class),
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$this->createMock(RateLimitService::class),
			$access,
			$settings,
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
		);

		try {
			$controller->accessSave();
			self::fail('self-lockout must throw ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('self_lockout', $e->getErrorCode());
		}
	}
}
