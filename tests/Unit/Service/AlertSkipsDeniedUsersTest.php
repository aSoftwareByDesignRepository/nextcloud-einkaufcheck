<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\AlertService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WorkspaceService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AlertSkipsDeniedUsersTest extends TestCase {
	public function testRunForWorkspaceDoesNotNotifyWhenRecipientsEmpty(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForWorkspace')->willReturn([
			[
				'watch_id' => 9,
				'offer' => [
					'store' => 'ALDI Nord',
					'brand' => '',
					'name' => 'Milch',
					'price' => 0.89,
					'per_kg' => null,
				],
			],
		]);
		$watch->method('listForJob')->willReturn([
			['id' => 9, 'query' => 'milch', 'enabled' => true, 'last_hit_key' => ''],
		]);
		$watch->expects($this->never())->method('claimHitKey');

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('fetch')->willReturn(['offers' => [
			['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => '', 'price' => 0.89],
		]]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('loadById')->with(7)->willReturn([
			'id' => 7,
			'plz' => '24149',
			'week' => 'current',
		]);

		$access = $this->createMock(AccessControlService::class);
		// notifyUserIdsForWorkspace already filters canUseApp — empty means everyone locked out.
		$access->method('notifyUserIdsForWorkspace')->with(7)->willReturn([]);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('createNotification');

		$alerts = $this->alerts($watch, $offers, $workspaces, $notifications, $access);
		self::assertSame(0, $alerts->runForWorkspace(7));
	}

	public function testRunAllSkipsMissingWorkspace(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('workspaceIdsWithWatches')->willReturn([42]);
		$watch->expects($this->never())->method('hitsForWorkspace');

		$offers = $this->createMock(OfferFetchService::class);
		$offers->expects($this->never())->method('fetch');

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('loadById')->with(42)->willReturn(null);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('createNotification');

		$alerts = $this->alerts(
			$watch,
			$offers,
			$workspaces,
			$notifications,
			$this->createMock(AccessControlService::class),
		);
		$result = $alerts->runAll();
		self::assertSame(1, $result['workspaces']);
		self::assertSame(0, $result['notified']);
	}

	public function testClaimBeforeNotifyPreventsDuplicateAndRollsBackOnFailure(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForWorkspace')->willReturn([
			[
				'watch_id' => 9,
				'offer' => [
					'store' => 'ALDI Nord',
					'brand' => '',
					'name' => 'Milch',
					'price' => 0.89,
					'per_kg' => null,
				],
			],
		]);
		$watch->method('listForJob')->willReturn([
			['id' => 9, 'query' => 'milch', 'enabled' => true, 'last_hit_key' => ''],
		]);
		$watch->expects($this->once())->method('claimHitKey')
			->with(7, 9, '', $this->isType('string'))
			->willReturn(true);
		$watch->expects($this->once())->method('setLastHitKey')
			->with(7, 9, '');

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('fetch')->willReturn(['offers' => [
			['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => '', 'price' => 0.89],
		]]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('loadById')->willReturn(['id' => 7, 'plz' => '24149']);

		$access = $this->createMock(AccessControlService::class);
		$access->method('notifyUserIdsForWorkspace')->willReturn(['alice']);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willThrowException(new \RuntimeException('push down'));

		$alerts = $this->alerts($watch, $offers, $workspaces, $notifications, $access);
		self::assertSame(0, $alerts->runForWorkspace(7));
	}

	public function testSecondWorkerSkipsWhenClaimLost(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForWorkspace')->willReturn([
			[
				'watch_id' => 9,
				'offer' => [
					'store' => 'ALDI Nord',
					'brand' => '',
					'name' => 'Milch',
					'price' => 0.89,
					'per_kg' => null,
				],
			],
		]);
		$watch->method('listForJob')->willReturn([
			['id' => 9, 'query' => 'milch', 'enabled' => true, 'last_hit_key' => ''],
		]);
		$watch->method('claimHitKey')->willReturn(false);

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('fetch')->willReturn(['offers' => [
			['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => '', 'price' => 0.89],
		]]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('loadById')->willReturn(['id' => 7, 'plz' => '24149']);

		$access = $this->createMock(AccessControlService::class);
		$access->method('notifyUserIdsForWorkspace')->willReturn(['alice']);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('createNotification');

		$alerts = $this->alerts($watch, $offers, $workspaces, $notifications, $access);
		self::assertSame(0, $alerts->runForWorkspace(7));
	}

	public function testNotifiesEligibleRecipientsOnly(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForWorkspace')->willReturn([
			[
				'watch_id' => 9,
				'offer' => [
					'store' => 'ALDI Nord',
					'brand' => '',
					'name' => 'Milch',
					'price' => 0.89,
					'per_kg' => null,
				],
			],
		]);
		$watch->method('listForJob')->willReturn([
			['id' => 9, 'query' => 'milch', 'enabled' => true, 'last_hit_key' => ''],
		]);
		$watch->method('claimHitKey')->willReturn(true);

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('fetch')->willReturn(['offers' => [
			['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => '', 'price' => 0.89],
		]]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('loadById')->willReturn(['id' => 7, 'plz' => '24149']);

		$access = $this->createMock(AccessControlService::class);
		$access->method('notifyUserIdsForWorkspace')->willReturn(['alice']);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->once())->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify')->with($notification);

		$alerts = $this->alerts($watch, $offers, $workspaces, $notifications, $access);
		self::assertSame(1, $alerts->runForWorkspace(7));
	}

	private function alerts(
		WatchService $watch,
		OfferFetchService $offers,
		WorkspaceService $workspaces,
		INotificationManager $notifications,
		AccessControlService $access,
	): AlertService {
		return new AlertService(
			$watch,
			$offers,
			$workspaces,
			$notifications,
			$this->createMock(LoggerInterface::class),
			$access,
		);
	}
}
