<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\AlertService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\WatchService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AlertSkipsDeniedUsersTest extends TestCase {
	public function testRunAllDoesNotFetchOrNotifyWhenUserCannotUseApp(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('usersWithWatches')->willReturn(['locked-out']);
		$watch->expects($this->never())->method('hitsForUser');
		$watch->expects($this->never())->method('list');

		$offers = $this->createMock(OfferFetchService::class);
		$offers->expects($this->never())->method('fetch');
		$offers->expects($this->never())->method('getUserPrefs');

		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->with('locked-out')->willReturn(false);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('createNotification');

		$alerts = new AlertService(
			$watch,
			$offers,
			$notifications,
			$this->createMock(LoggerInterface::class),
			$access,
		);
		$result = $alerts->runAll();
		self::assertSame(1, $result['users']);
		self::assertSame(0, $result['notified']);
	}

	public function testRunAllStillRunsForAllowedUsers(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('usersWithWatches')->willReturn(['alice']);
		$watch->method('hitsForUser')->willReturn([]);
		$watch->method('list')->willReturn([]);

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
		$offers->expects($this->once())->method('fetch')->willReturn(['offers' => []]);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->with('alice')->willReturn(true);

		$alerts = new AlertService(
			$watch,
			$offers,
			$this->createMock(INotificationManager::class),
			$this->createMock(LoggerInterface::class),
			$access,
		);
		$result = $alerts->runAll();
		self::assertSame(0, $result['notified']);
	}

	public function testClaimBeforeNotifyPreventsDuplicateAndRollsBackOnFailure(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForUser')->willReturn([
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
		$watch->method('list')->willReturn([
			['id' => 9, 'query' => 'milch', 'enabled' => true, 'last_hit_key' => ''],
		]);
		$watch->expects($this->once())->method('claimHitKey')
			->with('alice', 9, '', $this->isType('string'))
			->willReturn(true);
		$watch->expects($this->once())->method('setLastHitKey')
			->with('alice', 9, '');

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
		$offers->method('fetch')->willReturn(['offers' => [
			['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => '', 'price' => 0.89],
		]]);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willThrowException(new \RuntimeException('push down'));

		$alerts = new AlertService(
			$watch,
			$offers,
			$notifications,
			$this->createMock(LoggerInterface::class),
			$this->createMock(AccessControlService::class),
		);
		self::assertSame(0, $alerts->runForUser('alice'));
	}

	public function testSecondWorkerSkipsWhenClaimLost(): void {
		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForUser')->willReturn([
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
		$watch->method('list')->willReturn([
			['id' => 9, 'query' => 'milch', 'enabled' => true, 'last_hit_key' => ''],
		]);
		$watch->method('claimHitKey')->willReturn(false);

		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
		$offers->method('fetch')->willReturn(['offers' => [
			['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => '', 'price' => 0.89],
		]]);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('createNotification');

		$alerts = new AlertService(
			$watch,
			$offers,
			$notifications,
			$this->createMock(LoggerInterface::class),
			$this->createMock(AccessControlService::class),
		);
		self::assertSame(0, $alerts->runForUser('alice'));
	}
}
