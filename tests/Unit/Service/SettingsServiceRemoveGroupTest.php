<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class SettingsServiceRemoveGroupTest extends TestCase {
	public function testRemoveGroupStripsOnlyThatGid(): void {
		$stored = [
			SettingsService::KEY_ACCESS_GROUPS => json_encode(['keep', 'gone'], JSON_THROW_ON_ERROR),
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				self::assertSame(Application::APP_ID, $app);
				return $stored[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				self::assertSame(Application::APP_ID, $app);
				$stored[$key] = $value;
			}
		);

		$svc = new SettingsService(
			$config,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
		);
		$svc->removeGroupFromLists('gone');
		self::assertSame(['keep'], $svc->getAccessGroups());
	}

	public function testRemoveGroupEmptyIsNoOp(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setAppValue');
		$svc = new SettingsService(
			$config,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
		);
		$svc->removeGroupFromLists('');
	}
}
