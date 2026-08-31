<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Shopping-space lifecycle, prefs, and member CRUD.
 */
class WorkspaceService {
	/** Soft ceiling against create spam / parallel ensurePersonal races. */
	public const MAX_WORKSPACES_PER_USER = 25;

	/**
	 * ILockingProvider keys live in oc_file_locks.key VARCHAR(64).
	 * Short prefix + md5 hex (32) keeps every key ≤ 39 chars.
	 */
	private const LOCK_PERSONAL_PREFIX = 'ekc-pw-';
	private const LOCK_CREATE_PREFIX = 'ekc-wc-';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly AccessControlService $access,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listForUser(string $userId): array {
		$ids = $this->access->workspacesForUser($userId);
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_ws')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('name', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$workspace = $this->hydrateRow($row);
			$workspace['role'] = $this->access->role((int)$workspace['id'], $userId);
			$out[] = $this->withCapabilities($workspace, $userId);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Opaque get: missing and unauthorized IDs both throw AccessDeniedException.
	 *
	 * @return array<string, mixed>
	 */
	public function getForUser(int $workspaceId, string $userId): array {
		if ($workspaceId < 1) {
			throw new AccessDeniedException();
		}
		$workspace = $this->loadById($workspaceId);
		if ($workspace === null) {
			throw new AccessDeniedException();
		}
		$role = $this->access->role($workspaceId, $userId);
		if ($role === null) {
			throw new AccessDeniedException();
		}
		$workspace['role'] = $role;
		$this->access->rememberLastWorkspace($userId, $workspaceId);
		return $this->withCapabilities($workspace, $userId);
	}

	/**
	 * Create a private personal workspace when the user has none.
	 *
	 * @return array<string, mixed>
	 */
	public function ensurePersonalWorkspace(string $userId): array {
		if ($userId === '') {
			throw new AccessDeniedException();
		}
		$existing = $this->listForUser($userId);
		if ($existing !== []) {
			return $this->pickPreferred($existing, $userId);
		}
		$lockKey = self::LOCK_PERSONAL_PREFIX . md5($userId);
		$acquired = false;
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			$acquired = true;
		} catch (LockedException) {
			// Another request is provisioning — wait briefly and re-list.
			usleep(150000);
			$existing = $this->listForUser($userId);
			if ($existing !== []) {
				return $this->pickPreferred($existing, $userId);
			}
			throw new ValidationException('Shopping space is busy. Try again.', [], 'list_busy');
		}
		try {
			$existing = $this->listForUser($userId);
			if ($existing !== []) {
				return $this->pickPreferred($existing, $userId);
			}
			return $this->createWorkspaceUnlocked($userId, 'My shopping list', AccessControlService::PRIVACY_PRIVATE);
		} finally {
			if ($acquired) {
				$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $existing
	 * @return array<string, mixed>
	 */
	private function pickPreferred(array $existing, string $userId): array {
		$last = $this->access->lastUsedWorkspace($userId);
		if ($last !== null) {
			foreach ($existing as $ws) {
				if ((int)$ws['id'] === $last) {
					return $ws;
				}
			}
		}
		return $existing[0];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createWorkspace(string $userId, string $name, string $privacyMode = AccessControlService::PRIVACY_PRIVATE): array {
		$privacyMode = $this->access->normalisePrivacyMode($privacyMode, AccessControlService::PRIVACY_PRIVATE);
		if (!$this->access->canCreateWorkspace($userId, $privacyMode)) {
			throw new AccessDeniedException();
		}
		$lockKey = self::LOCK_CREATE_PREFIX . md5($userId);
		$acquired = false;
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			$acquired = true;
		} catch (LockedException) {
			usleep(100000);
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				$acquired = true;
			} catch (LockedException) {
				throw new ValidationException('Shopping space is busy. Try again.', [], 'list_busy');
			}
		}
		try {
			return $this->createWorkspaceUnlocked($userId, $name, $privacyMode);
		} finally {
			if ($acquired) {
				try {
					$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
				}
			}
		}
	}

	/**
	 * Caller already holds personal/create membership lock when needed.
	 *
	 * @return array<string, mixed>
	 */
	private function createWorkspaceUnlocked(string $userId, string $name, string $privacyMode): array {
		$owned = $this->countIndividualMemberships($userId);
		if ($owned >= self::MAX_WORKSPACES_PER_USER) {
			throw new ValidationException(
				'You already have the maximum number of shopping spaces (' . self::MAX_WORKSPACES_PER_USER . ').',
				[],
				'workspace_limit',
			);
		}
		$name = $this->normaliseName($name);
		$now = time();
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('einkaufcheck_ws')
				->values([
					'name' => $qb->createNamedParameter($name),
					'privacy_mode' => $qb->createNamedParameter($privacyMode),
					'created_by' => $qb->createNamedParameter($userId),
					'plz' => $qb->createNamedParameter('24149'),
					'week' => $qb->createNamedParameter('current'),
					'show_images' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
			$id = (int)$this->db->lastInsertId('einkaufcheck_ws');

			$mqb = $this->db->getQueryBuilder();
			$mqb->insert('einkaufcheck_ws_mem')
				->values([
					'workspace_id' => $mqb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
					'user_id' => $mqb->createNamedParameter($userId),
					'role' => $mqb->createNamedParameter(AccessControlService::ROLE_MANAGER),
					'created_at' => $mqb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$this->access->forgetPrivacyModeCache($id);
		$this->access->rememberLastWorkspace($userId, $id);
		return $this->getForUser($id, $userId);
	}

	/**
	 * Soft-delete a shopping space the actor manages (cascade items/watches/members).
	 * After delete, if the actor has no spaces left, a personal space is re-provisioned.
	 *
	 * @return array{ok: true, activeWorkspaceId: int}
	 */
	public function deleteWorkspace(int $workspaceId, string $userId): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		// Destructive: only an individual manager seat — never app-admin break-glass alone.
		if ($this->access->individualMemberRole($workspaceId, $userId) !== AccessControlService::ROLE_MANAGER) {
			throw new AccessDeniedException();
		}
		$this->access->cascadeDeleteWorkspace($workspaceId);
		$remaining = $this->listForUser($userId);
		if ($remaining === []) {
			$ws = $this->ensurePersonalWorkspace($userId);
			return ['ok' => true, 'activeWorkspaceId' => (int)$ws['id']];
		}
		$preferred = $this->pickPreferred($remaining, $userId);
		$this->access->rememberLastWorkspace($userId, (int)$preferred['id']);
		return ['ok' => true, 'activeWorkspaceId' => (int)$preferred['id']];
	}

	/**
	 * Exposed for architecture tests: lock keys must fit oc_file_locks.key VARCHAR(64).
	 */
	public static function personalLockKey(string $userId): string {
		return self::LOCK_PERSONAL_PREFIX . md5($userId);
	}

	public static function createLockKey(string $userId): string {
		return self::LOCK_CREATE_PREFIX . md5($userId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function updateWorkspace(int $workspaceId, string $userId, array $payload): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$updates = [];
		if (array_key_exists('name', $payload)) {
			$updates['name'] = $this->normaliseName((string)$payload['name']);
		}
		if (array_key_exists('plz', $payload) || array_key_exists('week', $payload) || array_key_exists('show_images', $payload)) {
			$current = $this->loadById($workspaceId);
			if ($current === null) {
				throw new AccessDeniedException();
			}
			if (array_key_exists('plz', $payload)) {
				$plz = (string)$payload['plz'];
				$this->assertPlz($plz);
				$updates['plz'] = $plz;
			}
			if (array_key_exists('week', $payload)) {
				$week = (string)$payload['week'];
				$this->assertWeek($week);
				$updates['week'] = $week;
			}
			if (array_key_exists('show_images', $payload)) {
				$updates['show_images'] = InputCoercion::asBool($payload['show_images'], 'show_images') ? 1 : 0;
			}
		}
		$privacyRaw = $payload['privacyMode'] ?? $payload['privacy_mode'] ?? null;
		$privacyNext = null;
		if ($privacyRaw !== null && $privacyRaw !== '') {
			$privacyNext = $this->access->normalisePrivacyMode($privacyRaw);
			$updates['privacy_mode'] = $privacyNext;
		}

		if ($updates === []) {
			return $this->getForUser($workspaceId, $userId);
		}
		$updates['updated_at'] = time();

		$this->db->beginTransaction();
		try {
			WorkspaceRowLock::acquire($this->db, $workspaceId);
			if (isset($updates['privacy_mode'])) {
				$fresh = $this->loadById($workspaceId);
				if ($fresh === null) {
					throw new AccessDeniedException();
				}
				$currentPrivacy = (string)($fresh['privacyMode'] ?? AccessControlService::PRIVACY_PRIVATE);
				$nextPrivacy = (string)$updates['privacy_mode'];
				if ($nextPrivacy !== $currentPrivacy) {
					$this->assertPrivacyTransitionAllowed($workspaceId, $userId, $nextPrivacy);
				} else {
					unset($updates['privacy_mode']);
				}
			}
			$substantive = $updates;
			unset($substantive['updated_at']);
			if ($substantive === []) {
				$this->db->commit();
				return $this->getForUser($workspaceId, $userId);
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('einkaufcheck_ws');
			foreach ($updates as $col => $value) {
				$type = is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
				$qb->set($col, $qb->createNamedParameter($value, $type));
			}
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		if (isset($updates['privacy_mode'])) {
			$this->access->forgetPrivacyModeCache($workspaceId);
		}
		return $this->getForUser($workspaceId, $userId);
	}

	/**
	 * @return array{plz: string, week: string, show_images: bool}
	 */
	public function getPrefs(int $workspaceId, string $userId): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_VIEWER);
		$ws = $this->loadById($workspaceId);
		if ($ws === null) {
			throw new AccessDeniedException();
		}
		return [
			'plz' => (string)$ws['plz'],
			'week' => (string)$ws['week'],
			'show_images' => !empty($ws['showImages']),
		];
	}

	/**
	 * Manager-only prefs write.
	 *
	 * @return array{plz: string, week: string, show_images: bool}
	 */
	public function savePrefs(int $workspaceId, string $userId, string $plz, string $week, ?bool $showImages = null): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$this->assertPlz($plz);
		$this->assertWeek($week);
		$qb = $this->db->getQueryBuilder();
		$qb->update('einkaufcheck_ws')
			->set('plz', $qb->createNamedParameter($plz))
			->set('week', $qb->createNamedParameter($week))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
		if ($showImages !== null) {
			$qb->set('show_images', $qb->createNamedParameter($showImages ? 1 : 0, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $this->getPrefs($workspaceId, $userId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listMembers(int $workspaceId, string $userId): array {
		$this->getForUser($workspaceId, $userId);
		$rows = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('role', 'ASC');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$user = $this->userManager->get((string)$row['user_id']);
			$rows[] = [
				'type' => 'user',
				'id' => (int)$row['id'],
				'workspaceId' => (int)$row['workspace_id'],
				'userId' => (string)$row['user_id'],
				'displayName' => $user !== null ? $user->getDisplayName() : (string)$row['user_id'],
				'enabled' => $user !== null ? $user->isEnabled() : false,
				'role' => (string)$row['role'],
				'createdAt' => (int)$row['created_at'],
			];
		}
		$result->closeCursor();

		$gq = $this->db->getQueryBuilder();
		$gq->select('*')
			->from('einkaufcheck_ws_grp')
			->where($gq->expr()->eq('workspace_id', $gq->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('role', 'ASC')
			->addOrderBy('gid', 'ASC');
		$gResult = $gq->executeQuery();
		while ($row = $gResult->fetch()) {
			$gid = (string)$row['gid'];
			$group = $this->groupManager->get($gid);
			$memberCount = null;
			if ($group !== null) {
				$count = $group->count();
				$memberCount = is_int($count) ? max(0, $count) : null;
			}
			$rows[] = [
				'type' => 'group',
				'id' => (int)$row['id'],
				'workspaceId' => (int)$row['workspace_id'],
				'groupId' => $gid,
				'displayName' => $group !== null ? $group->getDisplayName() : $gid,
				'exists' => $group !== null,
				'memberCount' => $memberCount,
				'role' => (string)$row['role'],
				'createdAt' => (int)$row['created_at'],
			];
		}
		$gResult->closeCursor();
		return $rows;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<array<string, mixed>>
	 */
	public function addMember(int $workspaceId, string $userId, array $payload): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$candidate = trim((string)($payload['userId'] ?? $payload['user_id'] ?? ''));
		if ($candidate === '') {
			throw new ValidationException('userId is required.', ['userId' => 'Required'], 'user_required');
		}
		if ($this->userManager->get($candidate) === null) {
			throw new ValidationException('Unknown user.', ['userId' => 'Unknown'], 'unknown_user');
		}
		$role = $this->normaliseRole((string)($payload['role'] ?? AccessControlService::ROLE_VIEWER));
		if ($this->individualMemberId($workspaceId, $candidate) !== null) {
			throw new ValidationException('User is already a member.', [], 'already_member');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('einkaufcheck_ws_mem')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($candidate),
				'role' => $qb->createNamedParameter($role),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
		return $this->listMembers($workspaceId, $userId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<array<string, mixed>>
	 */
	public function updateMember(int $memberId, string $userId, array $payload): array {
		$row = $this->loadMember($memberId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$role = $this->normaliseRole((string)($payload['role'] ?? $row['role']));

		$this->db->beginTransaction();
		try {
			WorkspaceRowLock::acquire($this->db, $workspaceId);
			$fresh = $this->loadMember($memberId);
			if ($fresh === null || (int)$fresh['workspace_id'] !== $workspaceId) {
				throw new AccessDeniedException();
			}
			if ($role !== AccessControlService::ROLE_MANAGER && (string)$fresh['role'] === AccessControlService::ROLE_MANAGER) {
				$this->ensureNotLastManager($workspaceId, (int)$fresh['id']);
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('einkaufcheck_ws_mem')
				->set('role', $qb->createNamedParameter($role))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->listMembers($workspaceId, $userId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function removeMember(int $memberId, string $userId): array {
		$row = $this->loadMember($memberId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);

		$this->db->beginTransaction();
		try {
			WorkspaceRowLock::acquire($this->db, $workspaceId);
			$fresh = $this->loadMember($memberId);
			if ($fresh === null || (int)$fresh['workspace_id'] !== $workspaceId) {
				throw new AccessDeniedException();
			}
			if ((string)$fresh['role'] === AccessControlService::ROLE_MANAGER) {
				$this->ensureNotLastManager($workspaceId, (int)$fresh['id']);
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('einkaufcheck_ws_mem')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$this->access->forgetLastWorkspace((string)$row['user_id'], $workspaceId);
		return $this->listMembers($workspaceId, $userId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<array<string, mixed>>
	 */
	public function addGroupMember(int $workspaceId, string $userId, array $payload): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		if ($this->access->privacyMode($workspaceId) === AccessControlService::PRIVACY_PRIVATE) {
			throw new ValidationException(
				'Private shopping spaces allow individual members only. Groups cannot be assigned.',
				[],
				'private_workspace_groups_forbidden',
			);
		}
		$gid = trim((string)($payload['groupId'] ?? $payload['group_id'] ?? ''));
		if ($gid === '') {
			throw new ValidationException('groupId is required.', ['groupId' => 'Required'], 'group_required');
		}
		if ($this->groupManager->get($gid) === null) {
			throw new ValidationException('Unknown group.', ['groupId' => 'Unknown'], 'unknown_group');
		}
		$role = $this->normaliseGroupRole((string)($payload['role'] ?? AccessControlService::ROLE_VIEWER));
		if ($this->groupAssignmentId($workspaceId, $gid) !== null) {
			throw new ValidationException('This group is already assigned.', [], 'already_member');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('einkaufcheck_ws_grp')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
				'gid' => $qb->createNamedParameter($gid),
				'role' => $qb->createNamedParameter($role),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
		return $this->listMembers($workspaceId, $userId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<array<string, mixed>>
	 */
	public function updateGroupMember(int $groupMemberId, string $userId, array $payload): array {
		$row = $this->loadGroupMember($groupMemberId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		if ($this->access->privacyMode($workspaceId) === AccessControlService::PRIVACY_PRIVATE) {
			throw new ValidationException(
				'Private shopping spaces allow individual members only. Groups cannot be assigned.',
				[],
				'private_workspace_groups_forbidden',
			);
		}
		$role = $this->normaliseGroupRole((string)($payload['role'] ?? $row['role']));
		$qb = $this->db->getQueryBuilder();
		$qb->update('einkaufcheck_ws_grp')
			->set('role', $qb->createNamedParameter($role))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupMemberId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $this->listMembers($workspaceId, $userId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function removeGroupMember(int $groupMemberId, string $userId): array {
		$row = $this->loadGroupMember($groupMemberId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('einkaufcheck_ws_grp')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupMemberId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $this->listMembers($workspaceId, $userId);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function loadById(int $workspaceId): ?array {
		if ($workspaceId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_ws')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->hydrateRow($row);
	}

	private function countIndividualMemberships(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	private function assertPrivacyTransitionAllowed(int $workspaceId, string $userId, string $to): void {
		if ($this->access->individualMemberRole($workspaceId, $userId) !== AccessControlService::ROLE_MANAGER) {
			throw new AccessDeniedException();
		}
		if ($to === AccessControlService::PRIVACY_PRIVATE && $this->countGroupAssignments($workspaceId) > 0) {
			throw new ValidationException(
				'Remove all group assignments before making this shopping space private.',
				[],
				'workspace_has_group_members',
			);
		}
	}

	private function ensureNotLastManager(int $workspaceId, int $memberIdBeingChanged): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(AccessControlService::ROLE_MANAGER)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($memberIdBeingChanged, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ((int)($row['count'] ?? 0) === 0) {
			throw new ValidationException(
				'Cannot remove or downgrade the last shopping-space manager. Promote another member first.',
				[],
				'last_manager',
			);
		}
	}

	private function countGroupAssignments(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('einkaufcheck_ws_grp')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadMember(int $memberId): ?array {
		if ($memberId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function individualMemberId(int $workspaceId, string $userId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : (int)$row['id'];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadGroupMember(int $groupMemberId): ?array {
		if ($groupMemberId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_ws_grp')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupMemberId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function groupAssignmentId(int $workspaceId, string $gid): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('einkaufcheck_ws_grp')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : (int)$row['id'];
	}

	private function normaliseRole(string $role): string {
		$role = strtolower(trim($role));
		if (!in_array($role, [AccessControlService::ROLE_MANAGER, AccessControlService::ROLE_CONTRIBUTOR, AccessControlService::ROLE_VIEWER], true)) {
			throw new ValidationException('Invalid role.', ['role' => 'Invalid'], 'invalid_role');
		}
		return $role;
	}

	private function normaliseGroupRole(string $role): string {
		$role = strtolower(trim($role));
		if (!in_array($role, AccessControlService::GROUP_ASSIGNABLE_ROLES, true)) {
			throw new ValidationException(
				'Groups can be viewers or contributors only.',
				['role' => 'Invalid'],
				'invalid_role',
			);
		}
		return $role;
	}

	private function normaliseName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 128) {
			throw new ValidationException(
				'Name must be between 1 and 128 characters.',
				['name' => 'Length'],
				'invalid_name',
			);
		}
		return $name;
	}

	private function assertPlz(string $plz): void {
		if (!preg_match('/^\d{5}$/', $plz)) {
			throw new ValidationException('Postal code must be exactly 5 digits.', ['plz' => 'Invalid'], 'invalid_plz');
		}
	}

	private function assertWeek(string $week): void {
		if (!in_array($week, OfferFetchService::WEEKS, true)) {
			throw new ValidationException('Week must be this week or next week.', ['week' => 'Invalid'], 'invalid_week');
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrateRow(array $row): array {
		return [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'privacyMode' => (string)$row['privacy_mode'],
			'createdBy' => (string)$row['created_by'],
			'plz' => (string)$row['plz'],
			'week' => (string)$row['week'],
			'showImages' => (int)($row['show_images'] ?? 0) === 1,
			'createdAt' => (int)$row['created_at'],
			'updatedAt' => (int)$row['updated_at'],
		];
	}

	/**
	 * @param array<string, mixed> $workspace
	 * @return array<string, mixed>
	 */
	private function withCapabilities(array $workspace, string $userId): array {
		$privacy = (string)($workspace['privacyMode'] ?? AccessControlService::PRIVACY_PRIVATE);
		$role = (string)($workspace['role'] ?? $this->access->role((int)$workspace['id'], $userId) ?? '');
		$rank = AccessControlService::ROLE_RANK[$role] ?? 0;
		$individualManager = $this->access->individualMemberRole((int)$workspace['id'], $userId)
			=== AccessControlService::ROLE_MANAGER;
		$effectiveManager = $rank >= AccessControlService::ROLE_RANK[AccessControlService::ROLE_MANAGER];
		$workspace['capabilities'] = [
			// Privacy mode changes require an individual manager seat (not admin break-glass alone).
			'canManagePrivacy' => $individualManager,
			'canAssignGroups' => $individualManager && $privacy !== AccessControlService::PRIVACY_PRIVATE,
			// Invite matches API: any effective manager (incl. app-admin on standard).
			'canInvite' => $effectiveManager,
			'canDelete' => $individualManager,
			'canEditList' => $rank >= AccessControlService::ROLE_RANK[AccessControlService::ROLE_CONTRIBUTOR],
			'canManageWatches' => $rank >= AccessControlService::ROLE_RANK[AccessControlService::ROLE_CONTRIBUTOR],
			'canManageSettings' => $effectiveManager,
		];
		return $workspace;
	}
}