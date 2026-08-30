<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class OfferFetchPeekCacheTest extends TestCase {
	public function testPeekCacheMissDoesNotAcquireFetchLock(): void {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$svc = new OfferFetchService($config, $locking, $time, null, null);
		self::assertNull($svc->peekCache('24149', 'current'));
	}

	public function testPeekCacheRejectsInvalidPlz(): void {
		$svc = new OfferFetchService(
			$this->createMock(IConfig::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			null,
			null,
		);
		$this->expectException(ValidationException::class);
		$svc->peekCache('munich', 'current');
	}

	public function testPeekCacheHitReturnsSanitizedPayloadWithoutLock(): void {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$payload = json_encode([
			'offers' => [
				[
					'store' => 'ALDI Nord',
					'name' => 'Milch',
					'brand' => 'Milsani',
					'price' => 0.89,
					'errors' => 'RAW TRACEBACK MUST NOT LEAK',
				],
			],
			'errors' => ['python boom'],
			'counts' => ['aldi' => 1],
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

		$svc = new OfferFetchService($config, $locking, $time, null, null);
		$hit = $svc->peekCache('24149', 'current');
		self::assertIsArray($hit);
		self::assertSame('hit', $hit['cache']);
		self::assertArrayNotHasKey('errors', $hit);
		self::assertSame('Milch', $hit['offers'][0]['name']);
		self::assertArrayNotHasKey('errors', $hit['offers'][0]);
	}

	public function testPeekCacheStripsJavascriptAndHttpUrls(): void {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$payload = json_encode([
			'offers' => [
				[
					'store' => 'ALDI Nord',
					'name' => 'Milch',
					'brand' => 'Milsani',
					'price' => 0.89,
					'url' => 'javascript:alert(1)',
				],
				[
					'store' => 'Lidl',
					'name' => 'Sahne',
					'brand' => 'Milbona',
					'price' => 1.19,
					'url' => 'http://evil.example/steal',
				],
				[
					'store' => 'Lidl',
					'name' => 'Butter',
					'brand' => 'Milbona',
					'price' => 1.49,
					'url' => 'https://user:pass@lidl.de/p/1',
				],
				[
					'store' => 'ALDI Nord',
					'name' => 'Quark',
					'brand' => 'Milsani',
					'price' => 0.59,
					'url' => 'https://www.aldi-nord.de/produkt/quark',
				],
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

		$svc = new OfferFetchService($config, $locking, $time, null, null);
		$hit = $svc->peekCache('24149', 'current');
		self::assertIsArray($hit);
		self::assertSame('', $hit['offers'][0]['url']);
		self::assertSame('', $hit['offers'][1]['url']);
		self::assertSame('', $hit['offers'][2]['url']);
		self::assertSame('https://www.aldi-nord.de/produkt/quark', $hit['offers'][3]['url']);
	}

	public function testPeekCacheKeepsAllowlistedImagesAndStripsTheRest(): void {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$payload = json_encode([
			'offers' => [
				[
					'store' => 'ALDI Nord',
					'name' => 'Kartoffeln',
					'brand' => '',
					'price' => 1.99,
					'image' => 'https://s7g10.scene7.com/is/image/aldinord/6104_18_2026_speisekartoffeln',
				],
				[
					'store' => 'Lidl',
					'name' => 'Sahne',
					'brand' => 'Milbona',
					'price' => 1.19,
					'image' => 'javascript:alert(1)',
				],
				[
					'store' => 'Lidl',
					'name' => 'Butter',
					'brand' => 'Milbona',
					'price' => 1.49,
					'image' => 'https://evil.example/steal.jpg',
				],
				[
					'store' => 'Lidl',
					'name' => 'Quark',
					'brand' => 'Milbona',
					'price' => 0.59,
					'image' => 'https://user:pass@www.lidl.de/assets/x.jpg',
				],
				[
					'store' => 'Lidl',
					'name' => 'Milch',
					'brand' => 'Milbona',
					'price' => 0.89,
					'image' => 'https://www.lidl.de/assets/gcp1a4e50af46214833bbc959fb460c8a82.jpg',
				],
				[
					'store' => 'ALDI Nord',
					'name' => 'Zwiebeln',
					'brand' => '',
					'price' => 0.79,
				],
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

		$svc = new OfferFetchService($config, $locking, $time, null, null);
		$hit = $svc->peekCache('24149', 'current');
		self::assertIsArray($hit);
		self::assertSame(
			'https://s7g10.scene7.com/is/image/aldinord/6104_18_2026_speisekartoffeln',
			$hit['offers'][0]['image'],
		);
		self::assertSame('', $hit['offers'][1]['image']);
		self::assertSame('', $hit['offers'][2]['image']);
		self::assertSame('', $hit['offers'][3]['image']);
		self::assertSame(
			'https://www.lidl.de/assets/gcp1a4e50af46214833bbc959fb460c8a82.jpg',
			$hit['offers'][4]['image'],
		);
		self::assertSame('', $hit['offers'][5]['image']);
	}

	public function testIsPersistableRejectsEmptyOffers(): void {
		self::assertFalse(OfferFetchService::isPersistableOfferPayload(['offers' => []]));
		self::assertFalse(OfferFetchService::isPersistableOfferPayload([]));
		self::assertFalse(OfferFetchService::isPersistableOfferPayload(['offers' => 'nope']));
		self::assertTrue(OfferFetchService::isPersistableOfferPayload([
			'offers' => [['store' => 'ALDI Nord', 'name' => 'Milch']],
		]));
	}

	public function testForceRefreshCoalescesOntoFreshCache(): void {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$payload = json_encode([
			'offers' => [
				[
					'store' => 'ALDI Nord',
					'name' => 'Milch',
					'brand' => 'Milsani',
					'price' => 0.89,
				],
			],
		], JSON_THROW_ON_ERROR);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($payload): string {
				if (str_ends_with($key, '_at')) {
					return (string)(1_700_000_000 - 15);
				}
				if (str_starts_with($key, 'offers_')) {
					return $payload;
				}
				return $default;
			}
		);
		$config->expects($this->never())->method('setAppValue');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$svc = new OfferFetchService($config, $locking, $time, null, null);
		$hit = $svc->fetch('24149', 'current', true);
		self::assertSame('hit', $hit['cache']);
		self::assertSame('Milch', $hit['offers'][0]['name']);
	}
}
