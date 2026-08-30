<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Directory search for access-door pickers (never raw ID typing).
 */
class DirectorySearchService {
	private const MIN_QUERY = 2;
	private const MAX_RESULTS = 25;

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * @return list<array{id: string, label: string}>
	 */
	public function searchUsers(string $query): array {
		$q = $this->normalizeQuery($query);
		$out = [];
		$seen = [];
		foreach ($this->userManager->searchDisplayName($q, self::MAX_RESULTS) as $user) {
			if (!$user->isEnabled()) {
				continue;
			}
			$uid = $user->getUID();
			if (isset($seen[$uid])) {
				continue;
			}
			$seen[$uid] = true;
			$dn = trim($user->getDisplayName());
			$out[] = [
				'id' => $uid,
				'label' => $dn !== '' ? $dn : $uid,
			];
		}
		if (count($out) < self::MAX_RESULTS) {
			foreach ($this->userManager->search($q, self::MAX_RESULTS - count($out)) as $user) {
				if (!$user->isEnabled()) {
					continue;
				}
				$uid = $user->getUID();
				if (isset($seen[$uid])) {
					continue;
				}
				$seen[$uid] = true;
				$dn = trim($user->getDisplayName());
				$out[] = [
					'id' => $uid,
					'label' => $dn !== '' ? $dn : $uid,
				];
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
