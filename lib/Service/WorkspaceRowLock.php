<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCP\IDBConnection;

/**
 * Exclusive workspace row lock for privacy toggles and last-manager checks.
 *
 * Callers MUST already be inside an open DB transaction.
 */
final class WorkspaceRowLock {
	public static function acquire(IDBConnection $db, int $workspaceId): void {
		if ($workspaceId < 1) {
			throw new \InvalidArgumentException('Invalid workspace.');
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')
			->from('einkaufcheck_ws')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new \InvalidArgumentException('Workspace not found.');
		}
	}
}
