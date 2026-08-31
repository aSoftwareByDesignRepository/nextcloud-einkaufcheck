<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Listener;

use OCA\EinkaufCheck\Service\AccessControlService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Drop a deleted Nextcloud group from shopping-space assignments and the app
 * door allow-list so a recreated GID cannot inherit leftover access.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
	public function __construct(
		private readonly AccessControlService $access,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GroupDeletedEvent) {
			return;
		}
		$gid = $event->getGroup()->getGID();
		if ($gid === '') {
			return;
		}
		try {
			$this->access->purgeGroup($gid);
		} catch (\Throwable $e) {
			$this->logger->warning('EinkaufCheck group-delete cleanup failed', [
				'gid' => $gid,
				'exception' => $e,
			]);
		}
	}
}
