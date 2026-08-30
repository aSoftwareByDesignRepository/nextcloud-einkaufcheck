<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCP\IGroupManager;

/**
 * App-level access door: open vs restricted allow-lists.
 * Nextcloud admins and delegated app admins always pass.
 */
class AccessControlService {
	public const DENIAL_RESTRICTED = 'restricted';

	/** @var array<string, bool> */
	private array $groupMembershipCache = [];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function canUseApp(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		if ($this->isAppAdmin($uid)) {
			return true;
		}
		if ($this->settings->getAccessMode() === SettingsService::MODE_OPEN) {
			return true;
		}
		if (in_array($uid, $this->settings->getAccessUsers(), true)) {
			return true;
		}
		foreach ($this->settings->getAccessGroups() as $gid) {
			if ($this->isUserInGroupCached($uid, $gid)) {
				return true;
			}
		}
		return false;
	}

	public function isAppAdmin(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		if ($this->groupManager->isAdmin($uid)) {
			return true;
		}
		return in_array($uid, $this->settings->getAppAdmins(), true);
	}

	public function assertAppAdmin(string $uid): void {
		if (!$this->isAppAdmin($uid)) {
			throw new AppAccessDeniedException(
				'App administrator required.',
				'admin_required',
			);
		}
	}

	public function assertCanUseApp(string $uid): void {
		if ($this->canUseApp($uid)) {
			return;
		}
		$reason = $this->settings->getAccessMode() === SettingsService::MODE_RESTRICTED
			? self::DENIAL_RESTRICTED
			: null;
		throw new AppAccessDeniedException('You are not allowed to use EinkaufCheck.', $reason);
	}

	private function isUserInGroupCached(string $userId, string $groupId): bool {
		$key = $userId . "\0" . $groupId;
		if (!array_key_exists($key, $this->groupMembershipCache)) {
			$this->groupMembershipCache[$key] = $this->groupManager->isInGroup($userId, $groupId);
		}
		return $this->groupMembershipCache[$key];
	}
}
