<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Privacy / membership choke points that must stay fail-closed.
 */
class AccessControlPrivacyGateTest extends TestCase {
	public function testGhostWorkspaceDeniesEvenAppAdmin(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->getMockBuilder(\stdClass::class)
			->addMethods(['select', 'from', 'where', 'expr', 'createNamedParameter', 'executeQuery'])
			->getMock();
		$expr = $this->getMockBuilder(\stdClass::class)->addMethods(['eq'])->getMock();
		$expr->method('eq')->willReturn('eq');
		$result = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch', 'closeCursor'])->getMock();
		$result->method('fetch')->willReturn(false);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new AccessControlService(
			$settings,
			$groups,
			$db,
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
		);

		$this->expectException(AccessDeniedException::class);
		$svc->ensureMinimumRole(999999, 'admin', AccessControlService::ROLE_VIEWER);
	}

	public function testMissingWorkspacePrivacyIsPrivateNotStandard(): void {
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->getMockBuilder(\stdClass::class)
			->addMethods(['select', 'from', 'where', 'expr', 'createNamedParameter', 'executeQuery'])
			->getMock();
		$expr = $this->getMockBuilder(\stdClass::class)->addMethods(['eq'])->getMock();
		$expr->method('eq')->willReturn('eq');
		$result = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch', 'closeCursor'])->getMock();
		$result->method('fetch')->willReturn(false);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new AccessControlService(
			$settings,
			$groups,
			$db,
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
		);

		self::assertSame(AccessControlService::PRIVACY_PRIVATE, $svc->privacyMode(42));
	}

	public function testCanCreatePrivateWhenDoorOpen(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getAccessMode')->willReturn(SettingsService::MODE_OPEN);
		$settings->method('getAccessUsers')->willReturn([]);
		$settings->method('getAccessGroups')->willReturn([]);
		$settings->method('getAppAdmins')->willReturn([]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		$svc = new AccessControlService(
			$settings,
			$groups,
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
		);

		self::assertTrue($svc->canCreateWorkspace('alice', AccessControlService::PRIVACY_PRIVATE));
		self::assertFalse($svc->canCreateWorkspace('alice', AccessControlService::PRIVACY_STANDARD));
	}

	public function testDefaultPrivacyNormalisesToPrivate(): void {
		$svc = new AccessControlService(
			$this->createMock(SettingsService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
		);
		self::assertSame(AccessControlService::PRIVACY_PRIVATE, $svc->normalisePrivacyMode(null));
		self::assertSame(AccessControlService::PRIVACY_PRIVATE, $svc->normalisePrivacyMode(''));
	}
}
