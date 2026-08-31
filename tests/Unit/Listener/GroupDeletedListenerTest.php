<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Listener;

use OCA\EinkaufCheck\Listener\GroupDeletedListener;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GroupDeletedListenerTest extends TestCase {
	public function testHandlePurgesGid(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('purgeGroup')->with('einkauf');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('einkauf');

		$listener = new GroupDeletedListener($access, $this->createMock(LoggerInterface::class));
		$listener->handle(new GroupDeletedEvent($group));
	}

	public function testIgnoresForeignEvents(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->never())->method('purgeGroup');
		$listener = new GroupDeletedListener($access, $this->createMock(LoggerInterface::class));
		$listener->handle(new Event());
	}

	public function testSwallowsCleanupFailures(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->method('purgeGroup')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('einkauf');

		$listener = new GroupDeletedListener($access, $logger);
		$listener->handle(new GroupDeletedEvent($group));
	}

	public function testEmptyGidIsIgnored(): void {
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->never())->method('purgeGroup');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('');
		$listener = new GroupDeletedListener($access, $this->createMock(LoggerInterface::class));
		$listener->handle(new GroupDeletedEvent($group));
	}
}
