<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Directory search for access-door and invite pickers (never raw ID typing in the UI).
 *
 * Scopes (portfolio ACCESS-AND-DIRECTORY-PICKERS §4):
 * - fullDirectory (app admins): Nextcloud-wide enabled-user match.
 * - peer (workspace managers): only users who share a Nextcloud group with the
 *   actor, or whose UID exactly equals the query (invite-by-known-login).
 *   Always filtered through canUseApp so Restricted mode cannot leak outsiders.
 */
class DirectorySearchService {
	private const MIN_QUERY = 2;
	private const MAX_RESULTS = 25;

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly AccessControlService $access,
	) {
	}

	/**
	 * @return list<array{id: string, label: string}>
	 */
	public function searchUsers(string $query, string $actorUserId = '', bool $fullDirectory = false): array {
		$q = $this->normalizeQuery($query);
		$raw = $this->collectUserMatches($q);
		if ($fullDirectory || $this->access->isAppAdmin($actorUserId)) {
			return array_slice($raw, 0, self::MAX_RESULTS);
		}
		$out = [];
		foreach ($raw as $row) {
			$uid = $row['id'];
			if (!$this->access->canUseApp($uid)) {
				continue;
			}
			if ($this->isExactUidQuery($q, $uid) || $this->access->sharesAnyGroup($actorUserId, $uid)) {
				$out[] = $row;
			}
			if (count($out) >= self::MAX_RESULTS) {
				break;
			}
		}
		// Exact UID not in prefix search results (short/obscure logins).
		if (count($out) < self::MAX_RESULTS && $this->looksLikeExactUidQuery($q)) {
			$user = $this->userManager->get($q);
			if ($user instanceof IUser && $user->isEnabled()) {
				$uid = $user->getUID();
				if ($this->access->canUseApp($uid) && !isset($this->indexById($out)[$uid])) {
					$dn = trim($user->getDisplayName());
					$out[] = [
						'id' => $uid,
						'label' => $dn !== '' ? $dn : $uid,
					];
				}
			}
		}
		return $out;
	}

	/**
	 * @return list<array{id: string, label: string}>
	 */
	public function searchGroups(string $query): array {
		$q = $this->normalizeQuery($query);
		$out = [];
		foreach ($this->groupManager->search($q, self::MAX_RESULTS) as $group) {
			$gid = $group->getGID();
			$dn = trim($group->getDisplayName());
			$out[] = [
				'id' => $gid,
				'label' => $dn !== '' ? $dn : $gid,
			];
		}
		return $out;
	}

	/**
	 * @return list<array{id: string, label: string}>
	 */
	private function collectUserMatches(string $q): array {
		$out = [];
		$seen = [];
		foreach ($this->userManager->searchDisplayName($q, self::MAX_RESULTS) as $user) {
			$this->appendUser($user, $seen, $out);
		}
		if (count($out) < self::MAX_RESULTS) {
			foreach ($this->userManager->search($q, self::MAX_RESULTS - count($out)) as $user) {
				$this->appendUser($user, $seen, $out);
			}
		}
		return $out;
	}

	/**
	 * @param array<string, true> $seen
	 * @param list<array{id: string, label: string}> $out
	 */
	private function appendUser(IUser $user, array &$seen, array &$out): void {
		if (!$user->isEnabled()) {
			return;
		}
		$uid = $user->getUID();
		if (isset($seen[$uid])) {
			return;
		}
		$seen[$uid] = true;
		$dn = trim($user->getDisplayName());
		$out[] = [
			'id' => $uid,
			'label' => $dn !== '' ? $dn : $uid,
		];
	}

	/**
	 * @param list<array{id: string, label: string}> $rows
	 * @return array<string, true>
	 */
	private function indexById(array $rows): array {
		$idx = [];
		foreach ($rows as $row) {
			$idx[$row['id']] = true;
		}
		return $idx;
	}

	private function isExactUidQuery(string $query, string $uid): bool {
		return strcasecmp($query, $uid) === 0;
	}

	private function looksLikeExactUidQuery(string $query): bool {
		return (bool)preg_match('/^[a-zA-Z0-9_.\-@]+$/', $query);
	}

	private function normalizeQuery(string $query): string {
		$q = trim($query);
		if (mb_strlen($q) < self::MIN_QUERY) {
			throw new ValidationException(
				'Type at least 2 characters to search.',
				['q' => 'min_length'],
				'search_too_short',
			);
		}
		return mb_substr($q, 0, 64);
	}
}
