<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Listener;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * GDPR / user-delete: drop shopping list, watches, preferences, and allow-list rows.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly SettingsService $settings,
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
			$this->db->beginTransaction();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('einkaufcheck_items')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete('einkaufcheck_watch')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->executeStatement();
			$this->db->commit();

			foreach ($this->config->getUserKeys($uid, Application::APP_ID) as $key) {
				$this->config->deleteUserValue($uid, Application::APP_ID, $key);
			}

			$this->settings->removeUserFromLists($uid);
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->logger->warning('EinkaufCheck user-delete cleanup failed', [
				'user' => $uid,
				'exception' => $e,
			]);
		}
	}
}
