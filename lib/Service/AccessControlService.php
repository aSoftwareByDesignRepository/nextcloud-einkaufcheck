<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * App door + workspace ACL for EinkaufCheck.
 *
 * Private workspaces: individual membership only — no app-admin bypass, no groups.
 * Standard workspaces: app admin is universal manager; groups allowed (viewer/contributor).
 */
class AccessControlService {
	public const DENIAL_RESTRICTED = 'restricted';

	public const ROLE_MANAGER = 'manager';
	public const ROLE_CONTRIBUTOR = 'contributor';
	public const ROLE_VIEWER = 'viewer';

	public const PRIVACY_STANDARD = 'standard';
	public const PRIVACY_PRIVATE = 'private';

	public const PRIVACY_MODES = [self::PRIVACY_STANDARD, self::PRIVACY_PRIVATE];

	public const ROLE_RANK = [
		self::ROLE_VIEWER => 1,
		self::ROLE_CONTRIBUTOR => 2,
		self::ROLE_MANAGER => 3,
	];

	/** Groups never hold manager — last-manager floor stays individual. */
	public const GROUP_ASSIGNABLE_ROLES = [self::ROLE_VIEWER, self::ROLE_CONTRIBUTOR];

	public const KEY_LAST_WORKSPACE = 'einkaufcheck_last_workspace';

	/** @var array<string, bool> */
	private array $groupMembershipCache = [];

	/** @var array<string, list<string>> */
	private array $userGroupIdsCache = [];

	/** @var array<int, string> */
	private array $privacyModeCache = [];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly IGroupManager $groupManager,
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
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

	/**
	 * Effective role inside a workspace, or null when denied.
	 */
	public function role(int $workspaceId, string $userId): ?string {
		if ($workspaceId < 1 || $userId === '') {
			return null;
		}
		$privacy = $this->privacyMode($workspaceId);
		if ($privacy === self::PRIVACY_PRIVATE) {
			return $this->individualRole($workspaceId, $userId);
		}
		if ($this->isAppAdmin($userId)) {
			return self::ROLE_MANAGER;
		}
		$candidates = [];
		$individual = $this->individualRole($workspaceId, $userId);
		if ($individual !== null) {
			$candidates[] = $individual;
		}
		foreach ($this->groupRolesForUser($workspaceId, $userId) as $groupRole) {
			$candidates[] = $groupRole;
		}
		return $this->strongestRole($candidates);
	}

	/**
	 * Fail closed for unknown IDs: treat as private so app-admin break-glass
	 * cannot invent membership on ghost workspace rows.
	 */
	public function privacyMode(int $workspaceId): string {
		if ($workspaceId < 1) {
			return self::PRIVACY_PRIVATE;
		}
		if (array_key_exists($workspaceId, $this->privacyModeCache)) {
			return $this->privacyModeCache[$workspaceId];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('privacy_mode')
			->from('einkaufcheck_ws')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			// Opaque: missing ≡ private-without-members (no admin bypass).
			$this->privacyModeCache[$workspaceId] = self::PRIVACY_PRIVATE;
			return self::PRIVACY_PRIVATE;
		}
		$mode = strtolower(trim((string)($row['privacy_mode'] ?? self::PRIVACY_PRIVATE)));
		if (!in_array($mode, self::PRIVACY_MODES, true)) {
			$mode = self::PRIVACY_PRIVATE;
		}
		$this->privacyModeCache[$workspaceId] = $mode;
		return $mode;
	}

	public function workspaceExists(int $workspaceId): bool {
		if ($workspaceId < 1) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('einkaufcheck_ws')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	public function forgetPrivacyModeCache(int $workspaceId): void {
		unset($this->privacyModeCache[$workspaceId]);
	}

	public function canCreateWorkspace(string $userId, string $privacyMode): bool {
		if ($userId === '') {
			return false;
		}
		$mode = strtolower(trim($privacyMode));
		if ($mode === self::PRIVACY_PRIVATE) {
			return $this->canUseApp($userId);
		}
		if ($mode === self::PRIVACY_STANDARD) {
			return $this->isAppAdmin($userId);
		}
		return false;
	}

	public function normalisePrivacyMode(mixed $raw, string $default = self::PRIVACY_PRIVATE): string {
		if ($raw === null || $raw === '') {
			return $default;
		}
		if (!is_string($raw) && !is_int($raw)) {
			throw new \InvalidArgumentException('privacy_mode must be standard or private.');
		}
		$mode = strtolower(trim((string)$raw));
		if (!in_array($mode, self::PRIVACY_MODES, true)) {
			throw new \InvalidArgumentException('privacy_mode must be standard or private.');
		}
		return $mode;
	}

	public function individualMemberRole(int $workspaceId, string $userId): ?string {
		return $this->individualRole($workspaceId, $userId);
	}

	/**
	 * Strongest role inherited from group assignments (capped at contributor).
	 */
	public function groupRole(int $workspaceId, string $userId): ?string {
		return $this->strongestRole($this->groupRolesForUser($workspaceId, $userId));
	}

	protected function individualRole(int $workspaceId, string $userId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$role = (string)$row['role'];
		return in_array($role, [self::ROLE_MANAGER, self::ROLE_CONTRIBUTOR, self::ROLE_VIEWER], true)
			? $role
			: null;
	}

	/**
	 * @return list<string>
	 */
	protected function groupRolesForUser(int $workspaceId, string $userId): array {
		$gids = $this->userGroupIds($userId);
		if ($gids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('einkaufcheck_ws_grp')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('gid', $qb->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$roles = [];
		while ($row = $result->fetch()) {
			$role = (string)$row['role'];
			if (in_array($role, self::GROUP_ASSIGNABLE_ROLES, true)) {
				$roles[] = $role;
			}
		}
		$result->closeCursor();
		return $roles;
	}

	/**
	 * @param list<string> $roles
	 */
	public function strongestRole(array $roles): ?string {
		$best = null;
		$bestRank = 0;
		foreach ($roles as $role) {
			$rank = self::ROLE_RANK[$role] ?? 0;
			if ($rank > $bestRank) {
				$bestRank = $rank;
				$best = $role;
			}
		}
		return $best;
	}

	/**
	 * @return list<string>
	 */
	private function userGroupIds(string $userId): array {
		if (!array_key_exists($userId, $this->userGroupIdsCache)) {
			$user = $userId !== '' ? $this->userManager->get($userId) : null;
			$this->userGroupIdsCache[$userId] = $user === null
				? []
				: array_values($this->groupManager->getUserGroupIds($user));
		}
		return $this->userGroupIdsCache[$userId];
	}

	public function ensureMembership(int $workspaceId, string $userId): string {
		if (!$this->workspaceExists($workspaceId)) {
			throw new AccessDeniedException();
		}
		$role = $this->role($workspaceId, $userId);
		if ($role === null) {
			throw new AccessDeniedException();
		}
		return $role;
	}

	public function ensureMinimumRole(int $workspaceId, string $userId, string $minimum): string {
		$role = $this->ensureMembership($workspaceId, $userId);
		if ((self::ROLE_RANK[$role] ?? 0) < (self::ROLE_RANK[$minimum] ?? PHP_INT_MAX)) {
			throw new AccessDeniedException();
		}
		return $role;
	}

	/**
	 * @return list<int>
	 */
	public function workspacesForUser(string $userId): array {
		if ($userId === '') {
			return [];
		}
		if ($this->isAppAdmin($userId)) {
			return $this->workspaceIdsVisibleToAppAdmin($userId);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('workspace_id')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['workspace_id'];
		}
		$result->closeCursor();

		$gids = $this->userGroupIds($userId);
		if ($gids !== []) {
			$gq = $this->db->getQueryBuilder();
			$gq->selectDistinct('g.workspace_id')
				->from('einkaufcheck_ws_grp', 'g')
				->innerJoin('g', 'einkaufcheck_ws', 'w', $gq->expr()->eq('g.workspace_id', 'w.id'))
				->where($gq->expr()->in('g.gid', $gq->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($gq->expr()->neq('w.privacy_mode', $gq->createNamedParameter(self::PRIVACY_PRIVATE)));
			$gResult = $gq->executeQuery();
			while ($row = $gResult->fetch()) {
				$ids[] = (int)$row['workspace_id'];
			}
			$gResult->closeCursor();
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @return list<int>
	 */
	private function workspaceIdsVisibleToAppAdmin(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('einkaufcheck_ws')
			->where($qb->expr()->neq('privacy_mode', $qb->createNamedParameter(self::PRIVACY_PRIVATE)))
			->orderBy('name', 'ASC');
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		$mq = $this->db->getQueryBuilder();
		$mq->select('m.workspace_id')
			->from('einkaufcheck_ws_mem', 'm')
			->innerJoin('m', 'einkaufcheck_ws', 'w', $mq->expr()->eq('m.workspace_id', 'w.id'))
			->where($mq->expr()->eq('m.user_id', $mq->createNamedParameter($userId)))
			->andWhere($mq->expr()->eq('w.privacy_mode', $mq->createNamedParameter(self::PRIVACY_PRIVATE)));
		$mResult = $mq->executeQuery();
		while ($row = $mResult->fetch()) {
			$ids[] = (int)$row['workspace_id'];
		}
		$mResult->closeCursor();

		return array_values(array_unique($ids));
	}

	public function rememberLastWorkspace(string $userId, int $workspaceId): void {
		if ($userId === '' || $workspaceId < 1) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_LAST_WORKSPACE, (string)$workspaceId);
	}

	public function lastUsedWorkspace(string $userId): ?int {
		if ($userId === '') {
			return null;
		}
		$value = (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_LAST_WORKSPACE, '0');
		return $value > 0 ? $value : null;
	}

	public function forgetLastWorkspace(string $userId, int $workspaceId): void {
		if ($this->lastUsedWorkspace($userId) === $workspaceId) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_LAST_WORKSPACE);
		}
	}

	public function countManagers(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(self::ROLE_MANAGER)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	/**
	 * True when both users share at least one Nextcloud group (or are the same person).
	 * Used to scope invite directory search without exposing the whole instance.
	 */
	public function sharesAnyGroup(string $actorUserId, string $candidateUserId): bool {
		if ($actorUserId === '' || $candidateUserId === '') {
			return false;
		}
		if ($actorUserId === $candidateUserId) {
			return true;
		}
		$left = $this->userGroupIds($actorUserId);
		if ($left === []) {
			return false;
		}
		$right = $this->userGroupIds($candidateUserId);
		if ($right === []) {
			return false;
		}
		return count(array_intersect($left, $right)) > 0;
	}

	/**
	 * Individual member UIDs for a workspace (no group expansion).
	 *
	 * @return list<string>
	 */
	public function individualMemberUserIds(int $workspaceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * UIDs to notify for watch hits: individual members + members of assigned groups
	 * (standard only — private has no groups). Filtered by canUseApp.
	 *
	 * @return list<string>
	 */
	public function notifyUserIdsForWorkspace(int $workspaceId): array {
		$uids = $this->individualMemberUserIds($workspaceId);
		if ($this->privacyMode($workspaceId) !== self::PRIVACY_PRIVATE) {
			$gq = $this->db->getQueryBuilder();
			$gq->select('gid')
				->from('einkaufcheck_ws_grp')
				->where($gq->expr()->eq('workspace_id', $gq->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
			$gResult = $gq->executeQuery();
			while ($row = $gResult->fetch()) {
				$group = $this->groupManager->get((string)$row['gid']);
				if ($group === null) {
					continue;
				}
				foreach ($group->getUsers() as $user) {
					$uids[] = $user->getUID();
				}
			}
			$gResult->closeCursor();
		}
		$uids = array_values(array_unique(array_filter($uids, static fn (string $u): bool => $u !== '')));
		return array_values(array_filter($uids, fn (string $u): bool => $this->canUseApp($u)));
	}

	/**
	 * Remove memberships; cascade-delete sole-owned workspaces (items/watches/groups/ws).
	 * Also clears last-workspace pointer and allow-list seats.
	 */
	public function purgeUser(string $userId): void {
		if ($userId === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('workspace_id')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$workspaceIds = [];
		while ($row = $result->fetch()) {
			$workspaceIds[] = (int)$row['workspace_id'];
		}
		$result->closeCursor();

		foreach (array_unique($workspaceIds) as $wsId) {
			$memberCount = $this->countIndividualMembers($wsId);
			$isSole = $memberCount <= 1;
			if ($isSole) {
				$this->deleteWorkspaceCascade($wsId);
			}
		}

		$dq = $this->db->getQueryBuilder();
		$dq->delete('einkaufcheck_ws_mem')
			->where($dq->expr()->eq('user_id', $dq->createNamedParameter($userId)))
			->executeStatement();

		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_LAST_WORKSPACE);
		foreach ($this->config->getUserKeys($userId, Application::APP_ID) as $key) {
			$this->config->deleteUserValue($userId, Application::APP_ID, $key);
		}
		$this->settings->removeUserFromLists($userId);
	}

	public function purgeGroup(string $gid): void {
		if ($gid === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('einkaufcheck_ws_grp')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->executeStatement();
		$this->settings->removeGroupFromLists($gid);
	}

	private function countIndividualMembers(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	private function deleteWorkspaceCascade(int $workspaceId): void {
		$this->cascadeDeleteWorkspace($workspaceId);
	}

	/**
	 * Hard-delete a shopping space and all list/watch/membership rows.
	 * Used by user purge and by manager-initiated workspace delete.
	 */
	public function cascadeDeleteWorkspace(int $workspaceId): void {
		if ($workspaceId < 1) {
			return;
		}
		foreach (['einkaufcheck_items', 'einkaufcheck_watch', 'einkaufcheck_ws_mem', 'einkaufcheck_ws_grp'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('einkaufcheck_ws')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$this->forgetPrivacyModeCache($workspaceId);
	}

	private function isUserInGroupCached(string $userId, string $groupId): bool {
		$key = $userId . "\0" . $groupId;
		if (!array_key_exists($key, $this->groupMembershipCache)) {
			$this->groupMembershipCache[$key] = $this->groupManager->isInGroup($userId, $groupId);
		}
		return $this->groupMembershipCache[$key];
	}
}
