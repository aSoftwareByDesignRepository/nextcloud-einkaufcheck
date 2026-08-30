<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class AlertService {
	public const SUBJECT = 'watch_hit';

	public function __construct(
		private readonly WatchService $watch,
		private readonly OfferFetchService $offers,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
		private readonly AccessControlService $access,
	) {
	}

	/**
	 * @return array{users: int, notified: int}
	 */
	public function runAll(): array {
		$users = $this->watch->usersWithWatches();
		$notified = 0;
		foreach ($users as $uid) {
			if (!$this->access->canUseApp($uid)) {
				continue;
			}
			try {
				$notified += $this->runForUser($uid);
			} catch (\Throwable $e) {
				$this->logger->warning('EinkaufCheck watch alert failed for {user}', [
					'user' => $uid,
					'exception' => $e,
				]);
			}
		}
		return ['users' => count($users), 'notified' => $notified];
	}

	public function runForUser(string $userId): int {
		$prefs = $this->offers->getUserPrefs($userId);
		$data = $this->offers->fetch($prefs['plz'], 'current', false);
		$offers = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		$hits = $this->watch->hitsForUser($userId, $offers);

		$byWatch = [];
		foreach ($hits as $hit) {
			$wid = (int)($hit['watch_id'] ?? 0);
			if ($wid <= 0) {
				continue;
			}
			$byWatch[$wid][] = $hit;
		}

		$watches = [];
		foreach ($this->watch->list($userId, true) as $w) {
			$watches[(int)$w['id']] = $w;
		}

		$sent = 0;
		foreach ($watches as $wid => $watch) {
			$group = $byWatch[$wid] ?? [];
			if ($group === []) {
				if (($watch['last_hit_key'] ?? '') !== '') {
					$this->watch->setLastHitKey($userId, $wid, '');
				}
				continue;
			}
			$keys = [];
			$lines = [];
			foreach ($group as $hit) {
				$o = $hit['offer'];
				$key = implode('|', [
					(string)$wid,
					(string)($o['store'] ?? ''),
					(string)($o['name'] ?? ''),
					(string)($o['price'] ?? ''),
				]);
				$keys[] = sha1($key);
				$price = isset($o['price']) && $o['price'] !== null
					? number_format((float)$o['price'], 2, ',', '.') . ' €'
					: '—';
				$unit = isset($o['per_kg']) && $o['per_kg'] !== null
					? ' (' . number_format((float)$o['per_kg'], 2, ',', '.') . ' €/kg)'
					: '';
				$lines[] = ($o['store'] ?? '') . ': ' . ($o['brand'] ? $o['brand'] . ' ' : '') . ($o['name'] ?? '') . ' — ' . $price . $unit;
			}
			sort($keys);
			$combined = sha1(implode(',', $keys));
			$oldKey = (string)($watch['last_hit_key'] ?? '');
			if ($combined === $oldKey) {
				continue;
			}

			// Claim first (CAS), then notify. Rollback the claim if notify fails so
			// a later run can retry — concurrent workers must not double-notify.
			if (!$this->watch->claimHitKey($userId, $wid, $oldKey, $combined)) {
				$this->logger->info('EinkaufCheck watch hit already claimed', [
					'user' => $userId,
					'watch' => $wid,
				]);
				continue;
			}

			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('watch', (string)$wid)
					->setSubject(self::SUBJECT, [
						'query' => (string)$watch['query'],
						'lines' => implode("\n", $lines),
						'count' => (string)count($lines),
					]);
				$this->notifications->notify($notification);
			} catch (\Throwable $e) {
				$this->watch->setLastHitKey($userId, $wid, $oldKey);
				$this->logger->warning('EinkaufCheck notify failed; hit key restored for retry', [
					'user' => $userId,
					'watch' => $wid,
					'exception' => $e,
				]);
				continue;
			}
			$sent++;
		}
		return $sent;
	}
}
