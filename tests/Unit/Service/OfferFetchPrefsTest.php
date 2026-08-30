<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\OfferFetchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class OfferFetchPrefsTest extends TestCase {
	public function testImagesDefaultOffAndOmittedSaveLeavesThem(): void {
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, mixed $default = '') use (&$store): string {
				return $store[$key] ?? (string)$default;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$store): void {
				$store[$key] = $value;
			}
		);

		$svc = new OfferFetchService(
			$config,
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			null,
			null,
		);

		$prefs = $svc->getUserPrefs('alice');
		self::assertSame('24149', $prefs['plz']);
		self::assertSame('current', $prefs['week']);
		self::assertFalse($prefs['show_images']);

		$svc->saveUserPrefs('alice', '80331', 'next');
		self::assertArrayNotHasKey('show_images', $store);
		self::assertFalse($svc->getUserPrefs('alice')['show_images']);

		$svc->saveUserPrefs('alice', '80331', 'next', true);
		self::assertTrue($svc->getUserPrefs('alice')['show_images']);
		self::assertSame('1', $store['show_images']);

		$svc->saveUserPrefs('alice', '24149', 'current');
		self::assertTrue($svc->getUserPrefs('alice')['show_images']);
		self::assertSame('24149', $svc->getUserPrefs('alice')['plz']);

		$svc->saveUserPrefs('alice', '24149', 'current', false);
		self::assertFalse($svc->getUserPrefs('alice')['show_images']);
		self::assertSame('0', $store['show_images']);
	}
}
