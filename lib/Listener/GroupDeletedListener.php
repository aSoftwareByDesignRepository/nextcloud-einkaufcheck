<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Listener;

use OCA\EinkaufCheck\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Drop a deleted Nextcloud group from the access allow-list so a recreated
 * GID cannot inherit leftover EinkaufCheck access.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
	public function __construct(
		private readonly SettingsService $settings,
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
			$this->settings->removeGroupFromLists($gid);
		} catch (\Throwable $e) {
			$this->logger->warning('EinkaufCheck group-delete cleanup failed', [
				'gid' => $gid,
				'exception' => $e,
			]);
		}
	}
}
