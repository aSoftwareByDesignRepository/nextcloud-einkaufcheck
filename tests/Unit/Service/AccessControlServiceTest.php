<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class AccessControlServiceTest extends TestCase {
	public function testOpenModeAllowsAnyone(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_OPEN);
		$groups->method('isAdmin')->willReturn(false);
		$settings->method('getAppAdmins')->willReturn([]);

		$acl = new AccessControlService($settings, $groups);
		self::assertTrue($acl->canUseApp('alice'));
	}

	public function testRestrictedFailsClosedWithoutAllowList(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_RESTRICTED);
		$settings->method('getAccessUsers')->willReturn([]);
		$settings->method('getAccessGroups')->willReturn([]);
		$settings->method('getAppAdmins')->willReturn([]);
		$groups->method('isAdmin')->willReturn(false);

		$acl = new AccessControlService($settings, $groups);
		self::assertFalse($acl->canUseApp('alice'));
		$this->expectException(AppAccessDeniedException::class);
		$acl->assertCanUseApp('alice');
	}

	public function testAppAdminBypassesRestrictedDoor(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_RESTRICTED);
		$settings->method('getAccessUsers')->willReturn([]);
		$settings->method('getAccessGroups')->willReturn([]);
		$settings->method('getAppAdmins')->willReturn(['alice']);
		$groups->method('isAdmin')->willReturn(false);

		$acl = new AccessControlService($settings, $groups);
		self::assertTrue($acl->isAppAdmin('alice'));
		self::assertTrue($acl->canUseApp('alice'));
	}

	public function testNcAdminIsAlwaysAppAdmin(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAppAdmins')->willReturn([]);
		$groups->method('isAdmin')->with('root')->willReturn(true);

		$acl = new AccessControlService($settings, $groups);
		self::assertTrue($acl->isAppAdmin('root'));
		self::assertTrue($acl->canUseApp('root'));
	}

	public function testEmptyUidIsDenied(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_OPEN);
		$acl = new AccessControlService($settings, $groups);
		self::assertFalse($acl->canUseApp(''));
		self::assertFalse($acl->isAppAdmin(''));
	}

	public function testRestrictedAllowsListedUser(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_RESTRICTED);
		$settings->method('getAccessUsers')->willReturn(['alice']);
		$settings->method('getAccessGroups')->willReturn([]);
		$settings->method('getAppAdmins')->willReturn([]);
		$groups->method('isAdmin')->willReturn(false);

		$acl = new AccessControlService($settings, $groups);
		self::assertTrue($acl->canUseApp('alice'));
		self::assertFalse($acl->canUseApp('bob'));
	}

	public function testRestrictedAllowsGroupMember(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_RESTRICTED);
		$settings->method('getAccessUsers')->willReturn([]);
		$settings->method('getAccessGroups')->willReturn(['einkauf']);
		$settings->method('getAppAdmins')->willReturn([]);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $uid === 'alice' && $gid === 'einkauf'
		);

		$acl = new AccessControlService($settings, $groups);
		self::assertTrue($acl->canUseApp('alice'));
		self::assertFalse($acl->canUseApp('bob'));
	}
}
