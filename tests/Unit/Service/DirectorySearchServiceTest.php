<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class DirectorySearchServiceTest extends TestCase {
	public function testQueryShorterThanTwoCharsRejected(): void {
		$svc = $this->svc();
		try {
			$svc->searchUsers('a', 'manager1', false);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('search_too_short', $e->getErrorCode());
		}
	}

	public function testBlankQueryRejected(): void {
		$svc = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->searchGroups('  ');
	}

	public function testDisabledUsersAreSkippedAndDuplicatesDeduped(): void {
		$enabled = $this->user('alice', 'Alice Admin', true);
		$disabled = $this->user('bob', 'Bob', false);
		$dup = $this->user('alice', 'Alice Admin', true);

		$users = $this->createMock(IUserManager::class);
		$users->method('searchDisplayName')->willReturn([$enabled, $disabled]);
		$users->method('search')->willReturn([$dup]);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);

		$svc = new DirectorySearchService($users, $this->createMock(IGroupManager::class), $access);
		$out = $svc->searchUsers('al', 'admin', true);
		self::assertCount(1, $out);
		self::assertSame('alice', $out[0]['id']);
		self::assertSame('Alice Admin', $out[0]['label']);
	}

	public function testPeerScopeExcludesNonGroupNonExactUsers(): void {
		$alice = $this->user('alice', 'Alice', true);
		$stranger = $this->user('stranger', 'Stranger', true);

		$users = $this->createMock(IUserManager::class);
		$users->method('searchDisplayName')->willReturn([$alice, $stranger]);
		$users->method('search')->willReturn([]);
		$users->method('get')->willReturn(null);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('mgr')->willReturn(false);
		$access->method('canUseApp')->willReturn(true);
		$access->method('sharesAnyGroup')->willReturnCallback(
			static fn (string $a, string $b): bool => $a === 'mgr' && $b === 'alice',
		);

		$svc = new DirectorySearchService($users, $this->createMock(IGroupManager::class), $access);
		$out = $svc->searchUsers('al', 'mgr', false);
		self::assertCount(1, $out);
		self::assertSame('alice', $out[0]['id']);
	}

	public function testPeerScopeAllowsExactUidEvenWithoutSharedGroup(): void {
		$roommate = $this->user('roommate', 'Room Mate', true);

		$users = $this->createMock(IUserManager::class);
		$users->method('searchDisplayName')->willReturn([]);
		$users->method('search')->willReturn([]);
		$users->method('get')->with('roommate')->willReturn($roommate);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('canUseApp')->with('roommate')->willReturn(true);
		$access->method('sharesAnyGroup')->willReturn(false);

		$svc = new DirectorySearchService($users, $this->createMock(IGroupManager::class), $access);
		$out = $svc->searchUsers('roommate', 'mgr', false);
		self::assertCount(1, $out);
		self::assertSame('roommate', $out[0]['id']);
	}

	public function testGroupSearchUsesDisplayNameFallback(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('einkauf');
		$group->method('getDisplayName')->willReturn('');

		$gm = $this->createMock(IGroupManager::class);
		$gm->method('search')->with('ei', 25)->willReturn([$group]);

		$svc = new DirectorySearchService(
			$this->createMock(IUserManager::class),
			$gm,
			$this->createMock(AccessControlService::class),
		);
		$out = $svc->searchGroups('ei');
		self::assertSame([['id' => 'einkauf', 'label' => 'einkauf']], $out);
	}

	private function svc(): DirectorySearchService {
		return new DirectorySearchService(
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(AccessControlService::class),
		);
	}

	private function user(string $uid, string $dn, bool $enabled): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn($enabled);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($dn);
		return $user;
	}
}
