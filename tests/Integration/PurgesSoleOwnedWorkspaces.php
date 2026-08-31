<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Integration;

use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\WorkspaceService;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Integration helpers: reclaim workspace quota so createWorkspace tests do not
 * fail after adversarial spam left users at MAX_WORKSPACES_PER_USER.
 */
trait PurgesSoleOwnedWorkspaces {
	/**
	 * Delete sole-owned shopping spaces for $userId until membership count
	 * is below MAX − $headroom (keeps at least the oldest membership when possible).
	 */
	protected function ensureWorkspaceCreateHeadroom(string $userId, int $headroom = 8): void {
		$db = \OC::$server->get(IDBConnection::class);
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$access = \OC::$server->get(AccessControlService::class);
		$max = WorkspaceService::MAX_WORKSPACES_PER_USER;
		$target = max(1, $max - $headroom);

		$this->purgeNonManagerMemberships($db, $userId);

		while ($this->countMemberships($db, $userId) > $target) {
			$wsId = $this->findManagerWorkspaceToDelete($db, $userId);
			if ($wsId === null) {
				$wsId = $this->findDeletableSoleOwnedWorkspace($db, $userId);
			}
			if ($wsId === null) {
				break;
			}
			try {
				$workspaces->deleteWorkspace($wsId, $userId);
			} catch (\Throwable) {
				$ref = new \ReflectionClass($access);
				$method = $ref->getMethod('cascadeDeleteWorkspace');
				$method->invoke($access, $wsId);
			}
		}
	}

	private function purgeNonManagerMemberships(IDBConnection $db, string $userId): void {
		$qb = $db->getQueryBuilder();
		$qb->delete('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->neq(
				'role',
				$qb->createNamedParameter(AccessControlService::ROLE_MANAGER),
			))
			->executeStatement();
	}

	private function findManagerWorkspaceToDelete(IDBConnection $db, string $userId): ?int {
		$qb = $db->getQueryBuilder();
		$qb->select('workspace_id')
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq(
				'role',
				$qb->createNamedParameter(AccessControlService::ROLE_MANAGER),
			))
			->orderBy('workspace_id', 'DESC');
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id !== false && $id !== null ? (int)$id : null;
	}

	private function countMemberships(IDBConnection $db, string $userId): int {
		$qb = $db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('einkaufcheck_ws_mem')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	/**
	 * Prefer newest sole-owned workspace so personal (often oldest) survives.
	 */
	private function findDeletableSoleOwnedWorkspace(IDBConnection $db, string $userId): ?int {
		$qb = $db->getQueryBuilder();
		$qb->select('m.workspace_id')
			->from('einkaufcheck_ws_mem', 'm')
			->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
			->orderBy('m.workspace_id', 'DESC');
		$result = $qb->executeQuery();
		$candidates = [];
		while ($row = $result->fetch()) {
			$candidates[] = (int)$row['workspace_id'];
		}
		$result->closeCursor();

		foreach ($candidates as $wsId) {
			$cq = $db->getQueryBuilder();
			$cq->select($cq->func()->count('*', 'count'))
				->from('einkaufcheck_ws_mem')
				->where($cq->expr()->eq('workspace_id', $cq->createNamedParameter($wsId, IQueryBuilder::PARAM_INT)));
			$cr = $cq->executeQuery();
			$crow = $cr->fetch();
			$cr->closeCursor();
			if ((int)($crow['count'] ?? 0) <= 1) {
				return $wsId;
			}
		}
		return null;
	}
}
