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
		private readonly WorkspaceService $workspaces,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
		private readonly AccessControlService $access,
	) {
	}

	/**
	 * @return array{workspaces: int, notified: int}
	 */
	public function runAll(): array {
		$workspaceIds = $this->watch->workspaceIdsWithWatches();
		$notified = 0;
		foreach ($workspaceIds as $wsId) {
			try {
				$notified += $this->runForWorkspace($wsId);
			} catch (\Throwable $e) {
				$this->logger->warning('EinkaufCheck watch alert failed for workspace {ws}', [
					'ws' => $wsId,
					'exception' => $e,
				]);
			}
		}
		return ['workspaces' => count($workspaceIds), 'notified' => $notified];
	}

	public function runForWorkspace(int $workspaceId): int {
		$ws = $this->workspaces->loadById($workspaceId);
		if ($ws === null) {
			return 0;
		}
		$plz = (string)($ws['plz'] ?? '24149');
		if (!preg_match('/^\d{5}$/', $plz)) {
			$plz = '24149';
		}
		$data = $this->offers->fetch($plz, 'current', false);
		$offers = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		$hits = $this->watch->hitsForWorkspace($workspaceId, $offers);

		$byWatch = [];
		foreach ($hits as $hit) {
			$wid = (int)($hit['watch_id'] ?? 0);
			if ($wid <= 0) {
				continue;
			}
			$byWatch[$wid][] = $hit;
		}

		$watches = [];
		foreach ($this->watch->listForJob($workspaceId, true) as $w) {
			$watches[(int)$w['id']] = $w;
		}

		$recipients = $this->access->notifyUserIdsForWorkspace($workspaceId);
		if ($recipients === []) {
			return 0;
		}

		$sent = 0;
		foreach ($watches as $wid => $watch) {
			$group = $byWatch[$wid] ?? [];
			if ($group === []) {
				if (($watch['last_hit_key'] ?? '') !== '') {
					$this->watch->setLastHitKey($workspaceId, $wid, '');
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

			if (!$this->watch->claimHitKey($workspaceId, $wid, $oldKey, $combined)) {
				$this->logger->info('EinkaufCheck watch hit already claimed', [
					'workspace' => $workspaceId,
					'watch' => $wid,
				]);
				continue;
			}

			$anyOk = false;
			foreach ($recipients as $userId) {
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
					$anyOk = true;
					$sent++;
				} catch (\Throwable $e) {
					$this->logger->warning('EinkaufCheck notify failed for member', [
						'user' => $userId,
						'workspace' => $workspaceId,
						'watch' => $wid,
						'exception' => $e,
					]);
				}
			}
			if (!$anyOk) {
				$this->watch->setLastHitKey($workspaceId, $wid, $oldKey);
				$this->logger->warning('EinkaufCheck notify failed for all members; hit key restored', [
					'workspace' => $workspaceId,
					'watch' => $wid,
				]);
			}
		}
		return $sent;
	}
}
