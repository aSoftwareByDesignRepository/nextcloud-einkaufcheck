<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class DirectorySearchServiceTest extends TestCase {
	public function testQueryShorterThanTwoCharsRejected(): void {
		$svc = new DirectorySearchService(
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
		);
		try {
			$svc->searchUsers('a');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('search_too_short', $e->getErrorCode());
		}
	}

	public function testBlankQueryRejected(): void {
		$svc = new DirectorySearchService(
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
		);
		$this->expectException(ValidationException::class);
		$svc->searchGroups('  ');
	}

	public function testDisabledUsersAreSkippedAndDuplicatesDeduped(): void {
		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$enabled->method('getUID')->willReturn('alice');
		$enabled->method('getDisplayName')->willReturn('Alice Admin');

		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$disabled->method('getUID')->willReturn('bob');
		$disabled->method('getDisplayName')->willReturn('Bob');

		$dup = $this->createMock(IUser::class);
		$dup->method('isEnabled')->willReturn(true);
		$dup->method('getUID')->willReturn('alice');
		$dup->method('getDisplayName')->willReturn('Alice Admin');

		$users = $this->createMock(IUserManager::class);
		$users->method('searchDisplayName')->willReturn([$enabled, $disabled]);
		$users->method('search')->willReturn([$dup]);

		$svc = new DirectorySearchService($users, $this->createMock(IGroupManager::class));
		$out = $svc->searchUsers('al');
		self::assertCount(1, $out);
		self::assertSame('alice', $out[0]['id']);
		self::assertSame('Alice Admin', $out[0]['label']);
	}

	public function testGroupSearchUsesDisplayNameFallback(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('einkauf');
		$group->method('getDisplayName')->willReturn('');

		$gm = $this->createMock(IGroupManager::class);
		$gm->method('search')->with('ei', 25)->willReturn([$group]);

		$svc = new DirectorySearchService($this->createMock(IUserManager::class), $gm);
		$out = $svc->searchGroups('ei');
		self::assertSame([['id' => 'einkauf', 'label' => 'einkauf']], $out);
	}
}
