<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class OfferFetchHistoryHookTest extends TestCase {
	public function testPeekCacheNeverRecordsHistory(): void {
		$history = $this->createMock(PriceHistoryService::class);
		$history->expects($this->never())->method('record');
		$history->expects($this->never())->method('hasWeekSnapshot');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$svc = new OfferFetchService(
			$config,
			$this->createMock(ILockingProvider::class),
			$time,
			null,
			null,
			$history,
		);
		self::assertNull($svc->peekCache('24149', 'current'));
	}

	public function testCacheHitRecordsWhenNoSnapshotYet(): void {
		$payload = json_encode([
			'offers' => [
				['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => 'Milsani', 'price' => 0.89],
			],
		], JSON_THROW_ON_ERROR);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($payload): string {
				if (str_ends_with($key, '_at')) {
					return (string)(1_700_000_000 - 10);
				}
				if (str_starts_with($key, 'offers_')) {
					return $payload;
				}
				return $default;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$history = $this->createMock(PriceHistoryService::class);
		$history->expects($this->once())->method('hasWeekSnapshot')->with('24149', 'current')->willReturn(false);
		$history->expects($this->once())->method('record')->with(
			'24149',
			'current',
			$this->callback(static function (array $offers): bool {
				return ($offers[0]['name'] ?? '') === 'Milch';
			}),
		);

		$svc = new OfferFetchService($config, $locking, $time, null, null, $history);
		$hit = $svc->fetch('24149', 'current', false);
		self::assertSame('hit', $hit['cache']);
	}

	public function testCacheHitSkipsRecordWhenSnapshotExists(): void {
		$payload = json_encode([
			'offers' => [
				['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => 'Milsani', 'price' => 0.89],
			],
		], JSON_THROW_ON_ERROR);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($payload): string {
				if (str_ends_with($key, '_at')) {
					return (string)(1_700_000_000 - 10);
				}
				if (str_starts_with($key, 'offers_')) {
					return $payload;
				}
				return $default;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$history = $this->createMock(PriceHistoryService::class);
		$history->expects($this->once())->method('hasWeekSnapshot')->willReturn(true);
		$history->expects($this->never())->method('record');

		$svc = new OfferFetchService(
			$config,
			$this->createMock(ILockingProvider::class),
			$time,
			null,
			null,
			$history,
		);
		$svc->fetch('24149', 'current', false);
	}

	public function testHistoryFailureDoesNotFailFetch(): void {
		$payload = json_encode([
			'offers' => [
				['store' => 'ALDI Nord', 'name' => 'Milch', 'brand' => 'Milsani', 'price' => 0.89],
			],
		], JSON_THROW_ON_ERROR);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($payload): string {
				if (str_ends_with($key, '_at')) {
					return (string)(1_700_000_000 - 10);
				}
				if (str_starts_with($key, 'offers_')) {
					return $payload;
				}
				return $default;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$history = $this->createMock(PriceHistoryService::class);
		$history->method('hasWeekSnapshot')->willReturn(false);
		$history->method('record')->willThrowException(new \RuntimeException('db down'));

		$svc = new OfferFetchService(
			$config,
			$this->createMock(ILockingProvider::class),
			$time,
			null,
			null,
			$history,
		);
		$hit = $svc->fetch('24149', 'current', false);
		self::assertSame('Milch', $hit['offers'][0]['name']);
	}
}
