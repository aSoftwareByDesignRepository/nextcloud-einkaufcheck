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

class ShoppingListService {
	public const MAX_ITEMS = 200;
	public const MAX_QTY = 99;
	public const PRICE_MAX = 9999.99;
	private const LOCK_PREFIX = 'ekc-li-';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_items')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('checked', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $this->normalize($row);
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function add(string $userId, array $payload): array {
		$name = trim((string)($payload['name'] ?? ''));
		if ($name === '') {
			throw new ValidationException('Item name is required.', ['name' => 'Required'], 'item_name_required');
		}
		$qty = $this->validatedQty($payload['qty'] ?? 1);
		$store = mb_substr((string)($payload['store'] ?? ''), 0, 64);
		$brand = mb_substr((string)($payload['brand'] ?? ''), 0, 128);
		$nameStored = mb_substr($name, 0, 512);
		$pack = mb_substr((string)($payload['pack'] ?? ''), 0, 255);
		$price = $this->validatedDecimal($payload['price'] ?? null, 'price');
		$perKg = $this->validatedDecimal($payload['per_kg'] ?? null, 'per_kg');
		$note = mb_substr((string)($payload['note'] ?? ''), 0, 255);
		return $this->withUserLock($userId, function () use ($userId, $store, $brand, $nameStored, $pack, $price, $perKg, $note, $qty): array {
			$existing = $this->findMergeable($userId, $store, $brand, $nameStored, $pack, $price);
			if ($existing !== null) {
				$next = (int)$existing['qty'] + $qty;
				if ($next > self::MAX_QTY) {
					throw new ValidationException(
						'Quantity must be between 1 and 99.',
						['qty' => 'Must be 1–99'],
						'invalid_qty',
					);
				}
				$qb = $this->db->getQueryBuilder();
				$qb->update('einkaufcheck_items')
					->set('qty', $qb->createNamedParameter($next, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->executeStatement();
				return $this->get($userId, (int)$existing['id']);
			}
			if ($this->countForUser($userId) >= self::MAX_ITEMS) {
				throw new ValidationException(
					'Shopping list is full.',
					['limit' => self::MAX_ITEMS],
					'list_full',
				);
			}
			$now = time();
			$qb = $this->db->getQueryBuilder();
			$qb->insert('einkaufcheck_items')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'store' => $qb->createNamedParameter($store),
					'brand' => $qb->createNamedParameter($brand),
					'name' => $qb->createNamedParameter($nameStored),
					'pack' => $qb->createNamedParameter($pack),
					'price' => $qb->createNamedParameter($price, IQueryBuilder::PARAM_STR),
					'per_kg' => $qb->createNamedParameter($perKg, IQueryBuilder::PARAM_STR),
					'qty' => $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT),
					'note' => $qb->createNamedParameter($note),
					'checked' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
			$id = (int)$this->db->lastInsertId('einkaufcheck_items');
			return $this->get($userId, $id);
		});
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function update(string $userId, int $id, array $payload): array {
		$existing = $this->get($userId, $id);
		$qty = array_key_exists('qty', $payload)
			? $this->validatedQty($payload['qty'])
			: (int)$existing['qty'];
		$checked = array_key_exists('checked', $payload)
			? (InputCoercion::asBool($payload['checked'], 'checked') ? 1 : 0)
			: ($existing['checked'] ? 1 : 0);
		$note = array_key_exists('note', $payload)
			? mb_substr((string)$payload['note'], 0, 255)
			: (string)$existing['note'];
		$qb = $this->db->getQueryBuilder();
		$qb->update('einkaufcheck_items')
			->set('qty', $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT))
			->set('checked', $qb->createNamedParameter($checked, IQueryBuilder::PARAM_INT))
			->set('note', $qb->createNamedParameter($note))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
		return $this->get($userId, $id);
	}

	public function delete(string $userId, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->delete('einkaufcheck_items')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
		if ($affected < 1) {
			throw new NotFoundException('List item not found.');
		}
	}

	public function clear(string $userId, string $storeFilter = ''): void {
		$storeFilter = self::normalizeStoreFilter($storeFilter);
		$this->withUserLock($userId, function () use ($userId, $storeFilter): void {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('einkaufcheck_items')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			if ($storeFilter !== '') {
				$qb->andWhere($qb->expr()->eq('store', $qb->createNamedParameter($storeFilter)));
			}
			$qb->executeStatement();
		});
	}

	/**
	 * @return array{text: string, whatsapp_url: string, csv: string, items: list<array<string,mixed>>}
	 */
	public function export(string $userId, string $storeFilter = ''): array {
		$storeFilter = self::normalizeStoreFilter($storeFilter);
		$items = $this->list($userId);
		if ($storeFilter !== '') {
			$items = array_values(array_filter(
				$items,
				static fn (array $item): bool => ($item['store'] ?? '') === $storeFilter,
			));
		}
		return self::formatExport($items, $storeFilter);
	}

	public static function normalizeStoreFilter(string $store): string {
		$store = trim($store);
		if ($store === '' || $store === 'all') {
			return '';
		}
		if (!in_array($store, ['ALDI Nord', 'Lidl'], true)) {
			throw new ValidationException(
				'Store must be empty, ALDI Nord, or Lidl.',
				['store' => 'Invalid store'],
				'invalid_store',
			);
		}
		return $store;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array{text: string, whatsapp_url: string, csv: string, items: list<array<string,mixed>>}
	 */
	public static function formatExport(array $items, string $storeFilter = ''): array {
		$title = 'Einkaufszettel';
		if ($storeFilter !== '') {
			$title .= ' — ' . $storeFilter;
		}
		$lines = [$title];
		$total = 0.0;
		$csv = "store;brand;name;pack;qty;price;per_kg;note;checked\n";
		$groups = [];
		foreach ($items as $item) {
			$key = (string)($item['store'] ?? '');
			if ($key === '') {
				$key = '—';
			}
			$groups[$key][] = $item;
		}
		$order = ['ALDI Nord', 'Lidl'];
		$orderedKeys = [];
		foreach ($order as $store) {
			if (isset($groups[$store])) {
				$orderedKeys[] = $store;
			}
		}
		$rest = array_keys($groups);
		sort($rest);
		foreach ($rest as $key) {
			if (!in_array($key, $orderedKeys, true)) {
				$orderedKeys[] = $key;
			}
		}
		$showHeadings = $storeFilter === '' && count($orderedKeys) > 1;
		foreach ($orderedKeys as $key) {
			if ($showHeadings) {
				$lines[] = $key;
			}
			foreach ($groups[$key] as $item) {
				$price = $item['price'] ?? null;
				$linePrice = '';
				if ($price !== null) {
					$sum = (float)$price * (int)$item['qty'];
					$total += $sum;
					$linePrice = ' — ' . number_format($sum, 2, ',', '.') . ' €';
				}
				$prefix = !empty($item['checked']) ? '☑' : '☐';
				$lines[] = sprintf(
					'%s %dx %s%s%s%s',
					$prefix,
					$item['qty'],
					($item['brand'] ?? '') !== '' ? $item['brand'] . ' ' : '',
					$item['name'] ?? '',
					($item['pack'] ?? '') !== '' ? ' (' . $item['pack'] . ')' : '',
					$linePrice
				);
				$csv .= implode(';', [
					self::csvCell((string)($item['store'] ?? '')),
					self::csvCell((string)($item['brand'] ?? '')),
					self::csvCell((string)($item['name'] ?? '')),
					self::csvCell((string)($item['pack'] ?? '')),
					(string)$item['qty'],
					$price === null ? '' : number_format((float)$price, 2, '.', ''),
					($item['per_kg'] ?? null) === null ? '' : number_format((float)$item['per_kg'], 2, '.', ''),
					self::csvCell((string)($item['note'] ?? '')),
					!empty($item['checked']) ? '1' : '0',
				]) . "\n";
			}
		}
		if ($total > 0) {
			$lines[] = 'Summe ca. ' . number_format($total, 2, ',', '.') . ' €';
		}
		$text = implode("\n", $lines);
		return [
			'text' => $text,
			'whatsapp_url' => 'https://wa.me/?text=' . rawurlencode($text),
			'csv' => $csv,
			'items' => $items,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get(string $userId, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('einkaufcheck_items')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new NotFoundException('List item not found.');
		}
		return $this->normalize($row);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize(array $row): array {
		return [
			'id' => (int)$row['id'],
			'store' => (string)$row['store'],
			'brand' => (string)$row['brand'],
			'name' => (string)$row['name'],
			'pack' => (string)$row['pack'],
			'price' => $row['price'] === null || $row['price'] === '' ? null : (float)$row['price'],
			'per_kg' => $row['per_kg'] === null || $row['per_kg'] === '' ? null : (float)$row['per_kg'],
			'qty' => (int)$row['qty'],
			'note' => (string)$row['note'],
			'checked' => (int)$row['checked'] === 1,
			'created_at' => (int)$row['created_at'],
		];
	}

	/**
	 * Serialize add/clear for one user so a split-trip empty cannot race a merge.
	 * Lock keys differ from RateLimitService (ekc-rl-) — no deadlock across layers.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withUserLock(string $userId, callable $fn): mixed {
		$lockKey = self::LOCK_PREFIX . md5($userId);
		$acquired = $this->acquireAddLock($lockKey);
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

	private function acquireAddLock(string $lockKey): bool {
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			return true;
		} catch (LockedException) {
			usleep(50_000);
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				return true;
			} catch (LockedException) {
				throw new ValidationException(
					'Shopping list is busy. Try again.',
					[],
					'list_busy',
				);
			}
		}
	}

	/**
	 * Unchecked row with the same product identity, if any.
	 * Checked (already bought) lines are left alone so + can start a new line.
	 *
	 * @return array{id: int, qty: int}|null
	 */
	private function findMergeable(
		string $userId,
		string $store,
		string $brand,
		string $name,
		string $pack,
		?string $price,
	): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'qty')
			->from('einkaufcheck_items')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('checked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('store', $qb->createNamedParameter($store)))
			->andWhere($qb->expr()->eq('brand', $qb->createNamedParameter($brand)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->andWhere($qb->expr()->eq('pack', $qb->createNamedParameter($pack)));
		if ($price === null) {
			$qb->andWhere($qb->expr()->isNull('price'));
		} else {
			$qb->andWhere($qb->expr()->eq('price', $qb->createNamedParameter($price)));
		}
		$qb->orderBy('id', 'ASC')->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return ['id' => (int)$row['id'], 'qty' => (int)$row['qty']];
	}

	private function countForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('einkaufcheck_items')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function validatedQty(mixed $value): int {
		if (is_bool($value) || is_array($value) || $value === null) {
			throw new ValidationException('Quantity must be a number.', ['qty' => 'Must be a number'], 'invalid_qty');
		}
		$int = filter_var($value, FILTER_VALIDATE_INT);
		if ($int === false) {
			throw new ValidationException(
				'Quantity must be a whole number between 1 and 99.',
				['qty' => 'Must be an integer 1–99'],
				'invalid_qty',
			);
		}
		if ($int < 1 || $int > self::MAX_QTY) {
			throw new ValidationException(
				'Quantity must be between 1 and 99.',
				['qty' => 'Must be 1–99'],
				'invalid_qty',
			);
		}
		return $int;
	}

	private function validatedDecimal(mixed $value, string $field): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			throw new ValidationException(
				'Price must be a number.',
				[$field => 'Must be a number'],
				'invalid_price',
			);
		}
		$n = (float)$value;
		if (!is_finite($n) || $n < 0 || $n > self::PRICE_MAX) {
			throw new ValidationException(
				'Price must be between 0 and 9999.99.',
				[$field => 'Must be 0–9999.99'],
				'invalid_price',
			);
		}
		return number_format($n, 2, '.', '');
	}

	private static function csvCell(string $value): string {
		if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
			$value = "'" . $value;
		}
		return '"' . str_replace('"', '""', $value) . '"';
	}
}
