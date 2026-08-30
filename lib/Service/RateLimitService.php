<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCA\EinkaufCheck\Exception\RateLimitExceededException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Sliding-window rate limiter. Counters live in per-user preferences so we never
 * depend on Redis, and we never embed the userId in an `oc_appconfig` key
 * (VARCHAR(64) — broken for LDAP/AD UUID-style UIDs).
 *
 * Storage shape:
 *   - table: oc_preferences (userid column holds the full Nextcloud UID ≤64)
 *   - key:   rate_limit:{action}  (always ≤64; action is validated)
 *
 * Exclusive lock serializes the read-modify-write across PHP-FPM workers so
 * concurrent mutations cannot stampede past the quota.
 */
class RateLimitService {
	/**
	 * Lock paths are stored in oc_file_locks.key (VARCHAR(64)).
	 * Keep prefix short; digest with md5 (32 hex) → always ≤64.
	 */
	private const LOCK_PREFIX = 'ekc-rl-';

	/** Nextcloud appconfig/preferences configkey column limit. */
	public const CONFIG_KEY_MAX_LENGTH = 64;

	private const KEY_PREFIX = 'rate_limit:';

	/** Action segment must leave room for KEY_PREFIX under CONFIG_KEY_MAX_LENGTH. */
	public const ACTION_MAX_LENGTH = 53;

	public function __construct(
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private ILockingProvider $locking,
	) {
	}

	public function assertAllowed(string $userId, string $action, int $max, int $windowSeconds): void {
		if ($userId === '') {
			throw new AppAccessDeniedException();
		}
		if (strlen($userId) > 64) {
			// Nextcloud UIDs are capped at 64; refuse rather than truncate (would collide).
			throw new AppAccessDeniedException();
		}

		$action = $this->normalizeAction($action);
		$max = max(1, $max);
		$windowSeconds = max(1, $windowSeconds);
		$prefKey = $this->preferenceKey($action);
		$lockKey = self::LOCK_PREFIX . md5($userId . "\0" . $action);

		$acquired = false;
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			$acquired = true;
		} catch (LockedException) {
			usleep(50_000);
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				$acquired = true;
			} catch (LockedException) {
				throw new RateLimitExceededException();
			}
		}

		try {
			$now = $this->timeFactory->getTime();
			$raw = (string)$this->config->getUserValue($userId, Application::APP_ID, $prefKey, '[]');
			$entries = $this->decodeEntries($raw);
			$cutoff = $now - $windowSeconds;
			$entries = array_values(array_filter(
				$entries,
				static fn ($ts): bool => is_int($ts) && $ts >= $cutoff
			));
			if (count($entries) >= $max) {
				throw new RateLimitExceededException();
			}
			$entries[] = $now;
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				$prefKey,
				json_encode($entries, JSON_THROW_ON_ERROR)
			);
		} finally {
			if ($acquired) {
				$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * Preference key for a rate-limit action.
	 */
	public function preferenceKey(string $action): string {
		$key = self::KEY_PREFIX . $this->normalizeAction($action);
		if (strlen($key) > self::CONFIG_KEY_MAX_LENGTH) {
			$key = self::KEY_PREFIX . substr(hash('sha256', $action), 0, 40);
		}
		return $key;
	}

	/**
	 * @return list<int|mixed>
	 */
	private function decodeEntries(string $raw): array {
		try {
			$entries = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($entries)) {
			return [];
		}
		return $entries;
	}

	private function normalizeAction(string $action): string {
		$action = strtolower(trim($action));
		if ($action === '' || !preg_match('/^[a-z0-9_]{1,' . self::ACTION_MAX_LENGTH . '}$/', $action)) {
			throw new AppAccessDeniedException();
		}
		return $action;
	}
}
