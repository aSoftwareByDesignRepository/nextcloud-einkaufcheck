<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * App-level access door and delegated admin lists (oc_appconfig).
 */
class SettingsService {
	public const KEY_ACCESS_MODE = 'access_mode';
	public const KEY_ACCESS_GROUPS = 'access_groups';
	public const KEY_ACCESS_USERS = 'access_users';
	public const KEY_APP_ADMINS = 'app_admins';

	public const MODE_OPEN = 'open';
	public const MODE_RESTRICTED = 'restricted';

	public const MAX_LIST_ENTRIES = 200;
	public const MAX_ID_LENGTH = 64;

	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function getAccessMode(): string {
		$mode = strtolower(trim((string)$this->config->getAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_MODE,
			self::MODE_OPEN,
		)));
		return $mode === self::MODE_RESTRICTED ? self::MODE_RESTRICTED : self::MODE_OPEN;
	}

	public function setAccessMode(string $mode): void {
		$mode = strtolower(trim($mode));
		if (!in_array($mode, [self::MODE_OPEN, self::MODE_RESTRICTED], true)) {
			throw new ValidationException(
				'access_mode must be open or restricted.',
				['access_mode' => 'Must be open or restricted'],
				'invalid_access_mode',
			);
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_MODE, $mode);
	}

	/**
	 * @return list<string>
	 */
	public function getAccessGroups(): array {
		return $this->decodeList(self::KEY_ACCESS_GROUPS);
	}

	/**
	 * @param list<mixed> $groups
	 */
	public function setAccessGroups(array $groups): void {
		$ids = $this->sanitizeIds($groups);
		foreach ($ids as $gid) {
			if ($this->groupManager->get($gid) === null) {
				throw new ValidationException(
					'One or more access groups do not exist.',
					['access_groups' => $gid],
					'unknown_group',
				);
			}
		}
		$this->encodeList(self::KEY_ACCESS_GROUPS, $ids);
	}

	/**
	 * @return list<string>
	 */
	public function getAccessUsers(): array {
		return $this->decodeList(self::KEY_ACCESS_USERS);
	}

	/**
	 * @param list<mixed> $users
	 */
	public function setAccessUsers(array $users): void {
		$ids = $this->sanitizeIds($users);
		foreach ($ids as $uid) {
			if ($this->userManager->get($uid) === null) {
				throw new ValidationException(
					'One or more access users do not exist.',
					['access_users' => $uid],
					'unknown_user',
				);
			}
		}
		$this->encodeList(self::KEY_ACCESS_USERS, $ids);
	}

	/**
	 * @return list<string>
	 */
	public function getAppAdmins(): array {
		return $this->decodeList(self::KEY_APP_ADMINS);
	}

	/**
	 * @param list<mixed> $users
	 */
	public function setAppAdmins(array $users): void {
		$ids = $this->sanitizeIds($users);
		foreach ($ids as $uid) {
			if ($this->userManager->get($uid) === null) {
				throw new ValidationException(
					'One or more app administrators do not exist.',
					['app_admins' => $uid],
					'unknown_user',
				);
			}
		}
		$this->encodeList(self::KEY_APP_ADMINS, $ids);
	}

	/**
	 * Raw access-door snapshot for transactional save + rollback.
	 *
	 * @return array{
	 *   access_mode: string,
	 *   access_groups: list<string>,
	 *   access_users: list<string>,
	 *   app_admins: list<string>
	 * }
	 */
	public function snapshotAccess(): array {
		return [
			'access_mode' => $this->getAccessMode(),
			'access_groups' => $this->getAccessGroups(),
			'access_users' => $this->getAccessUsers(),
			'app_admins' => $this->getAppAdmins(),
		];
	}

	/**
	 * Restore a snapshot without re-validating IDs (rollback after a failed save).
	 *
	 * @param array{
	 *   access_mode?: string,
	 *   access_groups?: list<string>,
	 *   access_users?: list<string>,
	 *   app_admins?: list<string>
	 * } $snapshot
	 */
	public function restoreAccess(array $snapshot): void {
		$mode = strtolower(trim((string)($snapshot['access_mode'] ?? self::MODE_OPEN)));
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_MODE,
			$mode === self::MODE_RESTRICTED ? self::MODE_RESTRICTED : self::MODE_OPEN,
		);
		$this->encodeList(self::KEY_ACCESS_GROUPS, $this->sanitizeIds($snapshot['access_groups'] ?? []));
		$this->encodeList(self::KEY_ACCESS_USERS, $this->sanitizeIds($snapshot['access_users'] ?? []));
		$this->encodeList(self::KEY_APP_ADMINS, $this->sanitizeIds($snapshot['app_admins'] ?? []));
	}

	/**
	 * Drop a deleted UID from live allow/admin lists without re-validating ghosts.
	 */
	public function removeUserFromLists(string $uid): void {
		if ($uid === '') {
			return;
		}
		$users = array_values(array_filter(
			$this->getAccessUsers(),
			static fn (string $id): bool => $id !== $uid,
		));
		$admins = array_values(array_filter(
			$this->getAppAdmins(),
			static fn (string $id): bool => $id !== $uid,
		));
		$this->encodeList(self::KEY_ACCESS_USERS, $users);
		$this->encodeList(self::KEY_APP_ADMINS, $admins);
	}

	/**
	 * Drop a deleted GID from the access-group allow-list without re-validating ghosts.
	 */
	public function removeGroupFromLists(string $gid): void {
		if ($gid === '') {
			return;
		}
		$groups = array_values(array_filter(
			$this->getAccessGroups(),
			static fn (string $id): bool => $id !== $gid,
		));
		$this->encodeList(self::KEY_ACCESS_GROUPS, $groups);
	}

	/**
	 * @return array{
	 *   access_mode: string,
	 *   access_groups: list<array{id: string, label: string}>,
	 *   access_users: list<array{id: string, label: string}>,
	 *   app_admins: list<array{id: string, label: string}>
	 * }
	 */
	public function getAll(): array {
		return [
			'access_mode' => $this->getAccessMode(),
			'access_groups' => $this->labeledGroups($this->getAccessGroups()),
			'access_users' => $this->labeledUsers($this->getAccessUsers()),
			'app_admins' => $this->labeledUsers($this->getAppAdmins()),
		];
	}

	/**
	 * @param list<string> $ids
	 * @return list<array{id: string, label: string}>
	 */
	private function labeledUsers(array $ids): array {
		$out = [];
		foreach ($ids as $uid) {
			$user = $this->userManager->get($uid);
			$dn = $user !== null ? trim($user->getDisplayName()) : '';
			$out[] = [
				'id' => $uid,
				'label' => $dn !== '' ? $dn : $uid,
			];
		}
		return $out;
	}

	/**
	 * @param list<string> $ids
	 * @return list<array{id: string, label: string}>
	 */
	private function labeledGroups(array $ids): array {
		$out = [];
		foreach ($ids as $gid) {
			$group = $this->groupManager->get($gid);
			$dn = $group !== null ? trim($group->getDisplayName()) : '';
			$out[] = [
				'id' => $gid,
				'label' => $dn !== '' ? $dn : $gid,
			];
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function decodeList(string $key): array {
		$raw = trim((string)$this->config->getAppValue(Application::APP_ID, $key, '[]'));
		if ($raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		return $this->sanitizeIds($decoded);
	}

	/**
	 * @param list<string> $values
	 */
	private function encodeList(string $key, array $values): void {
		$this->config->setAppValue(
			Application::APP_ID,
			$key,
			json_encode(array_values($values), JSON_THROW_ON_ERROR),
		);
	}

	/**
	 * Strip, drop empties, cap length and count, unique (order-preserving).
	 *
	 * @param list<mixed> $raw
	 * @return list<string>
	 */
	private function sanitizeIds(array $raw): array {
		$out = [];
		foreach ($raw as $value) {
			if (!is_string($value) && !is_int($value)) {
				continue;
			}
			$id = trim((string)$value);
			if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
				continue;
			}
			$out[$id] = true;
			if (count($out) >= self::MAX_LIST_ENTRIES) {
				break;
			}
		}
		return array_keys($out);
	}
}
