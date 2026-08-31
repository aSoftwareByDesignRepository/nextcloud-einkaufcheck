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
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * GDPR / user-delete: drop memberships, cascade sole-owned shopping spaces,
 * clear last-workspace pointer and app-door allow-list seats.
 *
 * Shared spaces keep their list/watches for remaining members (BudgetCheck pattern).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private readonly AccessControlService $access,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		if ($uid === '') {
			return;
		}
		try {
			$this->access->purgeUser($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('EinkaufCheck user-delete cleanup failed', [
				'user' => $uid,
				'exception' => $e,
			]);
		}
	}
}
