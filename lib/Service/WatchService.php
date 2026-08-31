<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\NotFoundException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class WatchService {
	/** @var list<string> */
	public const STORES = ['', 'ALDI Nord', 'Lidl'];

	private const QUERY_MIN = 3;
	private const QUERY_MAX = 200;
	private const PRICE_MAX = 9999.99;
	public const MAX_WATCHES = 50;
	private const LOCK_PREFIX = 'ekc-wa-';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly WatchMatchService $match,
		private readonly ILockingProvider $locking,
		private readonly AccessControlService $access,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(int $workspaceId, string $actorUserId, bool $enabledOnly = false): array {
		$this->access->ensureMinimumRole($workspaceId, $actorUserId, AccessControlService::ROLE_VIEWER);
		return $this->listRows($workspaceId, $enabledOnly);
	}

	/**
	 * Trusted job / cron path — no ACL. Never call from HTTP handlers.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForJob(int $workspaceId, bool $enabledOnly = false): array {
		return $this->listRows($workspaceId, $enabledOnly);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function listRows(int $workspaceId, bool $enabledOnly = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_watch')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		if ($enabledOnly) {
			$qb->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $this->normalize($row);
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return list<int>
	 */
	public function workspaceIdsWithWatches(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('workspace_id')
			->from('einkaufcheck_watch')
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$wid = (int)$row['workspace_id'];
			if ($wid > 0) {
				$ids[] = $wid;
			}
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function add(int $workspaceId, string $actorUserId, array $payload): array {
		$this->access->ensureMinimumRole($workspaceId, $actorUserId, AccessControlService::ROLE_CONTRIBUTOR);
		$fields = $this->validatedFields($payload, null);
		return $this->withWorkspaceLock($workspaceId, function () use ($workspaceId, $actorUserId, $fields): array {
			if ($this->countForWorkspace($workspaceId) >= self::MAX_WATCHES) {
				throw new ValidationException(
					'Watch list is full.',
					['limit' => self::MAX_WATCHES],
					'watch_full',
				);
			}
			$now = time();
			$qb = $this->db->getQueryBuilder();
			$qb->insert('einkaufcheck_watch')
				->values([
					'workspace_id' => $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
					'user_id' => $qb->createNamedParameter($actorUserId),
					'query' => $qb->createNamedParameter($fields['query']),
					'brand' => $qb->createNamedParameter($fields['brand']),
					'store' => $qb->createNamedParameter($fields['store']),
					'max_price' => $qb->createNamedParameter($fields['max_price'], IQueryBuilder::PARAM_STR),
					'max_per_kg' => $qb->createNamedParameter($fields['max_per_kg'], IQueryBuilder::PARAM_STR),
					'enabled' => $qb->createNamedParameter($fields['enabled'], IQueryBuilder::PARAM_INT),
					'last_hit_key' => $qb->createNamedParameter(''),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
			$id = (int)$this->db->lastInsertId('einkaufcheck_watch');
			return $this->get($workspaceId, $id);
		});
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function update(int $workspaceId, string $actorUserId, int $id, array $payload): array {
		$this->access->ensureMinimumRole($workspaceId, $actorUserId, AccessControlService::ROLE_CONTRIBUTOR);
		return $this->withWorkspaceLock($workspaceId, function () use ($workspaceId, $id, $payload): array {
			$existing = $this->get($workspaceId, $id);
			$fields = $this->validatedFields($payload, $existing);
			$resetHit = $fields['query'] !== (string)$existing['query']
				|| $fields['brand'] !== (string)$existing['brand']
				|| $fields['store'] !== (string)$existing['store']
				|| $fields['max_price'] !== $this->decimalString($existing['max_price'])
				|| $fields['max_per_kg'] !== $this->decimalString($existing['max_per_kg']);
			$qb = $this->db->getQueryBuilder();
			$qb->update('einkaufcheck_watch')
				->set('query', $qb->createNamedParameter($fields['query']))
				->set('brand', $qb->createNamedParameter($fields['brand']))
				->set('store', $qb->createNamedParameter($fields['store']))
				->set('max_price', $qb->createNamedParameter($fields['max_price'], IQueryBuilder::PARAM_STR))
				->set('max_per_kg', $qb->createNamedParameter($fields['max_per_kg'], IQueryBuilder::PARAM_STR))
				->set('enabled', $qb->createNamedParameter($fields['enabled'], IQueryBuilder::PARAM_INT));
			if ($resetHit) {
				$qb->set('last_hit_key', $qb->createNamedParameter(''));
			}
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return $this->get($workspaceId, $id);
		});
	}

	public function delete(int $workspaceId, string $actorUserId, int $id): void {
		$this->access->ensureMinimumRole($workspaceId, $actorUserId, AccessControlService::ROLE_CONTRIBUTOR);
		$this->withWorkspaceLock($workspaceId, function () use ($workspaceId, $id): void {
			$qb = $this->db->getQueryBuilder();
			$affected = $qb->delete('einkaufcheck_watch')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			if ($affected < 1) {
				throw new NotFoundException('Watch entry not found.');
			}
		});
	}

	public function setLastHitKey(int $workspaceId, int $id, string $key): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('einkaufcheck_watch')
			->set('last_hit_key', $qb->createNamedParameter(mb_substr($key, 0, 64)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Compare-and-swap last_hit_key so concurrent alert runs notify at most once.
	 */
	public function claimHitKey(int $workspaceId, int $id, string $oldKey, string $newKey): bool {
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('einkaufcheck_watch')
			->set('last_hit_key', $qb->createNamedParameter(mb_substr($newKey, 0, 64)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('last_hit_key', $qb->createNamedParameter($oldKey)))
			->executeStatement();
		return $affected > 0;
	}

	/**
	 * HTTP path: ACL then match. Prefer this over hitsForWorkspace from controllers.
	 *
	 * @param list<array<string, mixed>> $offers
	 * @return list<array<string, mixed>>
	 */
	public function hitsForUser(int $workspaceId, string $actorUserId, array $offers): array {
		$this->access->ensureMinimumRole($workspaceId, $actorUserId, AccessControlService::ROLE_VIEWER);
		return $this->hitsForWorkspace($workspaceId, $offers);
	}

	/**
	 * Job/trusted path — no ACL. Controllers must call hitsForUser instead.
	 *
	 * @param list<array<string, mixed>> $offers
	 * @return list<array<string, mixed>>
	 */
	public function hitsForWorkspace(int $workspaceId, array $offers): array {
		return $this->match->hits($this->listForJob($workspaceId, true), $offers);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get(int $workspaceId, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_watch')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new NotFoundException('Watch entry not found.');
		}
		return $this->normalize($row);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed>|null $existing
	 * @return array{query: string, brand: string, store: string, max_price: ?string, max_per_kg: ?string, enabled: int}
	 */
	private function validatedFields(array $payload, ?array $existing): array {
		$query = trim((string)($payload['query'] ?? ($existing['query'] ?? '')));
		$len = mb_strlen($query);
		if ($len < self::QUERY_MIN || $len > self::QUERY_MAX) {
			throw new ValidationException(
				'Query must be between 3 and 200 characters.',
				['query' => 'Length must be 3–200'],
				'query_length',
			);
		}

		$store = (string)($payload['store'] ?? ($existing['store'] ?? ''));
		$store = trim($store);
		if (!in_array($store, self::STORES, true)) {
			throw new ValidationException(
				'Store must be empty, ALDI Nord, or Lidl.',
				['store' => 'Invalid store'],
				'invalid_store',
			);
		}

		$maxPrice = array_key_exists('max_price', $payload)
			? ($payload['max_price'] ?? null)
			: ($existing['max_price'] ?? null);
		$maxPerKg = array_key_exists('max_per_kg', $payload)
			? ($payload['max_per_kg'] ?? null)
			: ($existing['max_per_kg'] ?? null);

		$brand = mb_substr(trim((string)($payload['brand'] ?? ($existing['brand'] ?? ''))), 0, 128);
		$enabled = array_key_exists('enabled', $payload)
			? (InputCoercion::asBool($payload['enabled'], 'enabled') ? 1 : 0)
			: (($existing['enabled'] ?? true) ? 1 : 0);

		return [
			'query' => $query,
			'brand' => $brand,
			'store' => $store,
			'max_price' => $this->validatedDecimal($maxPrice, 'max_price'),
			'max_per_kg' => $this->validatedDecimal($maxPerKg, 'max_per_kg'),
			'enabled' => $enabled,
		];
	}

	private function validatedDecimal(mixed $value, string $field): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			throw new ValidationException(
				'Price cap must be a number.',
				[$field => 'Must be a number'],
				'invalid_price',
			);
		}
		$n = (float)$value;
		if (!is_finite($n) || $n < 0 || $n > self::PRICE_MAX) {
			throw new ValidationException(
				'Price cap must be between 0 and 9999.99.',
				[$field => 'Must be 0–9999.99'],
				'invalid_price',
			);
		}
		return number_format($n, 2, '.', '');
	}

	private function countForWorkspace(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('einkaufcheck_watch')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Serialize watch mutations per workspace. Lock keys differ from list (`ekc-li-`)
	 * and rate-limit (`ekc-rl-`) — no cross-layer deadlock.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withWorkspaceLock(int $workspaceId, callable $fn): mixed {
		$lockKey = self::LOCK_PREFIX . $workspaceId;
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
				throw new ValidationException('Watch list is busy. Try again.', [], 'watch_busy');
			}
		}
		try {
			return $fn();
		} finally {
			if ($acquired) {
				try {
					$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
				}
			}
		}
	}

	private function decimalString(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		return number_format((float)$value, 2, '.', '');
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize(array $row): array {
		return [
			'id' => (int)$row['id'],
			'query' => (string)$row['query'],
			'brand' => (string)$row['brand'],
			'store' => (string)$row['store'],
			'max_price' => $row['max_price'] === null || $row['max_price'] === '' ? null : (float)$row['max_price'],
			'max_per_kg' => $row['max_per_kg'] === null || $row['max_per_kg'] === '' ? null : (float)$row['max_per_kg'],
			'enabled' => (int)$row['enabled'] === 1,
			'last_hit_key' => (string)$row['last_hit_key'],
			'created_at' => (int)$row['created_at'],
		];
	}
}
