<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Tests\Unit\Listener;

use OCA\EinkaufCheck\Listener\GroupDeletedListener;
use OCA\EinkaufCheck\Listener\UserDeletedListener;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkspacePurgeListenersTest extends TestCase {
	public function testUserDeletedCallsPurgeUser(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->expects(self::once())->method('purgeUser')->with('alice');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$listener = new UserDeletedListener($access, $this->createMock(LoggerInterface::class));
		$listener->handle(new UserDeletedEvent($user));
	}

	public function testGroupDeletedCallsPurgeGroup(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->expects(self::once())->method('purgeGroup')->with('family');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('family');
		$listener = new GroupDeletedListener($access, $this->createMock(LoggerInterface::class));
		$listener->handle(new GroupDeletedEvent($group));
	}
}
