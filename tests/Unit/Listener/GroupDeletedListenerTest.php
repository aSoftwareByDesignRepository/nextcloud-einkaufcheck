<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Listener;

use OCA\EinkaufCheck\Listener\GroupDeletedListener;
use OCA\EinkaufCheck\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GroupDeletedListenerTest extends TestCase {
	public function testHandleStripsGid(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('removeGroupFromLists')->with('einkauf');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('einkauf');

		$listener = new GroupDeletedListener($settings, $this->createMock(LoggerInterface::class));
		$listener->handle(new GroupDeletedEvent($group));
	}

	public function testIgnoresForeignEvents(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('removeGroupFromLists');
		$listener = new GroupDeletedListener($settings, $this->createMock(LoggerInterface::class));
		$listener->handle(new Event());
	}

	public function testSwallowsCleanupFailures(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('removeGroupFromLists')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('einkauf');

		$listener = new GroupDeletedListener($settings, $logger);
		$listener->handle(new GroupDeletedEvent($group));
	}

	public function testEmptyGidIsIgnored(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('removeGroupFromLists');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('');
		$listener = new GroupDeletedListener($settings, $this->createMock(LoggerInterface::class));
		$listener->handle(new GroupDeletedEvent($group));
	}
}
