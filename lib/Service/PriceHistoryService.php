<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Shared weekly price snapshots. ALDI Nord is nationwide (plz=*);
 * Lidl is per postcode. Not personal data — no user_id column.
 *
 * Search uses WatchMatchService (word-bounded), never SQL LIKE infix.
 */
class PriceHistoryService {
	public const TABLE = 'einkaufcheck_price_hist';
	public const RETAIN_WEEKS = 26;
	public const MAX_CHEAP = 40;
	public const MAX_STAPLES = 25;
	public const MAX_SEARCH = 25;
	public const MAX_SERIES = 26;
	public const MAX_RECORD = 2500;
	public const MAX_IDENTITIES = 3000;
	public const NATIONWIDE = '*';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $time,
		private readonly WatchMatchService $match,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	public function plzScope(string $store, string $plz): string {
		return $store === 'ALDI Nord' ? self::NATIONWIDE : $plz;
	}

	public function skuKey(string $store, string $brand, string $name, string $pack, string $plzScope): string {
		return sha1(implode("\0", [
			$this->match->normalize($store),
			$this->match->normalize($brand),
			$this->match->normalize($name),
			$this->match->normalize($pack),
			$plzScope,
		]));
	}

	public function weekStart(string $week, int $now, string $validFrom = ''): string {
		$tz = new \DateTimeZone('Europe/Berlin');
		$from = $this->parseDate($validFrom, $tz);
		if ($from !== null) {
			return $from->setISODate((int)$from->format('o'), (int)$from->format('W'), 1)->format('Y-m-d');
		}
		$dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
		if ($week === 'next') {
			$dt = $dt->modify('+7 days');
		}
		return $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'), 1)->format('Y-m-d');
	}

	public function hasWeekSnapshot(string $plz, string $week): bool {
		if (!preg_match('/^\d{5}$/', $plz) || !in_array($week, OfferFetchService::WEEKS, true)) {
			return false;
		}
		$weekStart = $this->weekStart($week, $this->time->getTime());
		$qb = $this->db->getQueryBuilder();
		$qb->select('sku_key')
			->from(self::TABLE)
			->where($qb->expr()->eq('week_start', $qb->createNamedParameter($weekStart)))
			->andWhere($this->scopeExpr($qb, $plz))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * @param list<array<string, mixed>> $offers
	 */
	public function record(string $plz, string $week, array $offers): int {
		if (!preg_match('/^\d{5}$/', $plz) || !in_array($week, OfferFetchService::WEEKS, true)) {
			return 0;
		}
		try {
			$this->prune();
		} catch (\Throwable $e) {
			$this->logger?->warning('EinkaufCheck price history prune failed', ['exception' => $e]);
		}
		$now = $this->time->getTime();
		$written = 0;
		$seen = [];
		foreach ($offers as $offer) {
			if ($written >= self::MAX_RECORD) {
				break;
			}
			if (!is_array($offer)) {
				continue;
			}
			$store = mb_substr(trim((string)($offer['store'] ?? '')), 0, 64);
			$name = mb_substr(trim((string)($offer['name'] ?? '')), 0, 512);
			if ($name === '' || ($store !== 'ALDI Nord' && $store !== 'Lidl')) {
				continue;
			}
			$price = $this->money($offer['price'] ?? null);
			$perKg = $this->money($offer['per_kg'] ?? null);
			if ($price === null && $perKg === null) {
				continue;
			}
			$brand = mb_substr(trim((string)($offer['brand'] ?? '')), 0, 128);
			$pack = mb_substr(trim((string)($offer['pack'] ?? '')), 0, 255);
			$scope = $this->plzScope($store, $plz);
			$key = $this->skuKey($store, $brand, $name, $pack, $scope);
			$weekStart = $this->weekStart($week, $now, (string)($offer['valid_from'] ?? ''));
			$dedupe = $key . '|' . $weekStart;
			if (isset($seen[$dedupe])) {
				continue;
			}
			$seen[$dedupe] = true;
			$this->upsert([
				'sku_key' => $key,
				'store' => $store,
				'brand' => $brand,
				'name' => $name,
				'pack' => $pack,
				'plz' => $scope,
				'week_start' => $weekStart,
				'price' => $price,
				'per_kg' => $perKg,
				'recorded_at' => $now,
			]);
			$written++;
		}
		return $written;
	}

	public function prune(): int {
		$cutoff = $this->weekStart('current', $this->time->getTime());
		try {
			$cutDt = new \DateTimeImmutable($cutoff, new \DateTimeZone('Europe/Berlin'));
			$oldest = $cutDt->modify('-' . self::RETAIN_WEEKS . ' weeks')->format('Y-m-d');
		} catch (\Exception) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->lt('week_start', $qb->createNamedParameter($oldest)))
			->executeStatement();
	}

	/**
	 * @param list<array<string, mixed>> $offers
	 * @param list<array<string, mixed>> $watches
	 * @return array<string, mixed>
	 */
	public function summarize(
		string $plz,
		string $week,
		array $offers,
		array $watches,
		string $query,
		string $storeFilter,
	): array {
		if (!preg_match('/^\d{5}$/', $plz)) {
			throw new ValidationException('Postal code must be exactly 5 digits.', ['plz' => 'Must match ^\\d{5}$'], 'invalid_plz');
		}
		if (!in_array($week, OfferFetchService::WEEKS, true)) {
			throw new ValidationException('Week must be current or next.', ['week' => 'Must be current or next'], 'invalid_week');
		}
		if ($storeFilter !== '' && $storeFilter !== 'all' && !in_array($storeFilter, WatchService::STORES, true)) {
			throw new ValidationException('Store must be empty, ALDI Nord, or Lidl.', ['store' => 'Invalid store'], 'invalid_store');
		}
		if ($storeFilter === 'all') {
			$storeFilter = '';
		}
		$query = trim($query);
		if ($query !== '') {
			$len = mb_strlen($query);
			if ($len < 3 || $len > 200) {
				throw new ValidationException(
					'Query must be between 3 and 200 characters.',
					['query' => 'Length must be 3–200'],
					'query_length',
				);
			}
		}

		$now = $this->time->getTime();
		$currentWeek = $this->weekStart($week, $now);
		$bounds = $this->weekBounds($plz);
		$liveBySku = $this->liveBySku($offers, $plz, $storeFilter);

		$staples = [];
		$cheap = [];
		$search = [];
		$usedKeys = [];

		if ($query === '') {
			$stapleKeys = [];
			foreach ($watches as $watch) {
				if (count($stapleKeys) >= self::MAX_STAPLES) {
					break;
				}
				if (isset($watch['enabled']) && !$watch['enabled']) {
					continue;
				}
				$hitOffer = $this->firstMatchingOffer($this->identityWatch($watch), $offers, $storeFilter);
				if ($hitOffer === null) {
					continue;
				}
				$key = $this->skuFromOffer($hitOffer, $plz);
				if ($key === null) {
					continue;
				}
				$stapleKeys[] = $key;
			}
			$groups = $this->seriesForKeys($stapleKeys);
			foreach ($watches as $watch) {
				if (count($staples) >= self::MAX_STAPLES) {
					break;
				}
				if (isset($watch['enabled']) && !$watch['enabled']) {
					continue;
				}
				$hitOffer = $this->firstMatchingOffer($this->identityWatch($watch), $offers, $storeFilter);
				if ($hitOffer === null) {
					continue;
				}
				$row = $this->cardForOffer($hitOffer, $plz, $currentWeek, $groups);
				if ($row === null || isset($usedKeys[$row['sku_key']])) {
					continue;
				}
				$row['from_watch'] = true;
				$row['watch_query'] = (string)($watch['query'] ?? '');
				$staples[] = $row;
				$usedKeys[$row['sku_key']] = true;
			}

			if ($bounds['count'] >= 2) {
				$cheapKeys = [];
				foreach ($offers as $offer) {
					if (!is_array($offer)) {
						continue;
					}
					if ($storeFilter !== '' && ($offer['store'] ?? '') !== $storeFilter) {
						continue;
					}
					$key = $this->skuFromOffer($offer, $plz);
					if ($key === null || isset($usedKeys[$key])) {
						continue;
					}
					$cheapKeys[$key] = $offer;
					if (count($cheapKeys) >= self::MAX_RECORD) {
						break;
					}
				}
				$cheapGroups = $this->seriesForKeys(array_keys($cheapKeys));
				foreach ($cheapKeys as $offer) {
					$row = $this->cardForOffer($offer, $plz, $currentWeek, $cheapGroups);
					if ($row === null || $row['verdict'] !== 'cheap') {
						continue;
					}
					$cheap[] = $row;
				}
				usort($cheap, static function (array $a, array $b): int {
					$da = (float)($a['drop_pct'] ?? 0);
					$db = (float)($b['drop_pct'] ?? 0);
					if ($da !== $db) {
						return $db <=> $da;
					}
					return strcmp((string)$a['name'], (string)$b['name']);
				});
				if (count($cheap) > self::MAX_CHEAP) {
					$cheap = array_slice($cheap, 0, self::MAX_CHEAP);
				}
			}
		} else {
			$watch = ['query' => $query, 'enabled' => true, 'store' => $storeFilter];
			$identities = $this->searchIdentities($plz, $storeFilter, $liveBySku);
			$matchedKeys = [];
			$matchedLive = [];
			foreach ($identities as $identity) {
				if (count($matchedKeys) >= self::MAX_SEARCH) {
					break;
				}
				$offer = [
					'store' => $identity['store'],
					'brand' => $identity['brand'],
					'name' => $identity['name'],
					'pack' => $identity['pack'],
					'price' => $identity['price'],
					'per_kg' => $identity['per_kg'],
				];
				if (!$this->match->matches($watch, $offer)) {
					continue;
				}
				$key = (string)$identity['sku_key'];
				$matchedKeys[] = $key;
				if (isset($liveBySku[$key])) {
					$matchedLive[$key] = $liveBySku[$key];
				}
			}
			$groups = $this->seriesForKeys($matchedKeys);
			foreach ($matchedKeys as $key) {
				$live = $matchedLive[$key] ?? null;
				$group = $groups[$key] ?? null;
				if ($live !== null) {
					$card = $this->cardForOffer($live, $plz, $currentWeek, $groups);
				} elseif ($group !== null) {
					$card = $this->cardFromGroup($group, $currentWeek, null);
				} else {
					$card = null;
				}
				if ($card !== null) {
					$search[] = $card;
				}
			}
		}

		return [
			'plz' => $plz,
			'week' => $week,
			'current_week' => $currentWeek,
			'weeks_tracked' => $bounds['count'],
			'oldest_week' => $bounds['oldest'],
			'newest_week' => $bounds['newest'],
			'cache' => $offers !== [] ? 'hit' : 'empty',
			'staples' => $staples,
			'cheap_now' => $cheap,
			'search' => $search,
		];
	}

	/**
	 * @param array<string, mixed> $offer
	 * @param array<string, array<string, mixed>> $groups
	 * @return array<string, mixed>|null
	 */
	private function cardForOffer(array $offer, string $plz, string $currentWeek, array $groups): ?array {
		$store = (string)($offer['store'] ?? '');
		$name = trim((string)($offer['name'] ?? ''));
		if ($name === '') {
			return null;
		}
		$scope = $this->plzScope($store, $plz);
		$key = $this->skuKey(
			$store,
			(string)($offer['brand'] ?? ''),
			$name,
			(string)($offer['pack'] ?? ''),
			$scope,
		);
		$group = $groups[$key] ?? [
			'sku_key' => $key,
			'store' => $store,
			'brand' => (string)($offer['brand'] ?? ''),
			'name' => $name,
			'pack' => (string)($offer['pack'] ?? ''),
			'plz' => $scope,
			'points' => [],
			'latest_price' => $this->money($offer['price'] ?? null),
			'latest_per_kg' => $this->money($offer['per_kg'] ?? null),
		];
		$group['latest_price'] = $this->money($offer['price'] ?? null) ?? ($group['latest_price'] ?? null);
		$group['latest_per_kg'] = $this->money($offer['per_kg'] ?? null) ?? ($group['latest_per_kg'] ?? null);
		return $this->cardFromGroup($group, $currentWeek, $offer);
	}

	/**
	 * @param array<string, mixed> $group
	 * @param array<string, mixed>|null $live
	 * @return array<string, mixed>|null
	 */
	private function cardFromGroup(array $group, string $currentWeek, ?array $live = null): ?array {
		$points = $group['points'] ?? [];
		if (!is_array($points)) {
			$points = [];
		}
		$byWeek = [];
		foreach ($points as $p) {
			if (!is_array($p)) {
				continue;
			}
			$w = (string)($p['week_start'] ?? '');
			if ($w === '') {
				continue;
			}
			$byWeek[$w] = [
				'week_start' => $w,
				'price' => $this->money($p['price'] ?? null),
				'per_kg' => $this->money($p['per_kg'] ?? null),
			];
		}
		if ($live !== null) {
			$byWeek[$currentWeek] = [
				'week_start' => $currentWeek,
				'price' => $this->money($live['price'] ?? null) ?? $this->money($group['latest_price'] ?? null),
				'per_kg' => $this->money($live['per_kg'] ?? null) ?? $this->money($group['latest_per_kg'] ?? null),
			];
		}
		ksort($byWeek);
		$series = array_values($byWeek);
		if (count($series) > self::MAX_SERIES) {
			$series = array_slice($series, -self::MAX_SERIES);
		}
		if ($series === []) {
			return null;
		}

		$metric = PriceTrendMath::seriesMetric($series);
		$currentVal = null;
		$others = [];
		foreach ($series as $pt) {
			$val = $metric['unit'] === 'kg'
				? $this->money($pt['per_kg'] ?? null)
				: $this->money($pt['price'] ?? null);
			if ($val === null) {
				continue;
			}
			if ((string)$pt['week_start'] === $currentWeek) {
				$currentVal = $val;
			} else {
				$others[] = $val;
			}
		}
		$stats = PriceTrendMath::classify($currentVal, $others);
		$onOfferNow = isset($byWeek[$currentWeek]);
		$currentPrice = $onOfferNow
			? $this->money($byWeek[$currentWeek]['price'] ?? null)
			: $this->money($series[array_key_last($series)]['price'] ?? null);
		$currentKg = $onOfferNow
			? $this->money($byWeek[$currentWeek]['per_kg'] ?? null)
			: $this->money($series[array_key_last($series)]['per_kg'] ?? null);

		return [
			'sku_key' => (string)$group['sku_key'],
			'store' => (string)$group['store'],
			'brand' => (string)$group['brand'],
			'name' => (string)$group['name'],
			'pack' => (string)$group['pack'],
			'unit' => $metric['unit'],
			'current' => $currentVal,
			'price' => $currentPrice,
			'per_kg' => $currentKg,
			'verdict' => $stats['verdict'],
			'drop_pct' => $stats['drop_pct'],
			'avg' => $stats['avg'],
			'min' => $stats['min'],
			'max' => $stats['max'],
			'is_lowest' => $stats['is_lowest'],
			'weeks' => $stats['weeks'],
			'on_offer_now' => $onOfferNow,
			'series' => $series,
			'from_watch' => false,
			'watch_query' => '',
			'image' => $live !== null ? OfferImagePolicy::sanitize($live['image'] ?? '') : '',
		];
	}

	/**
	 * Price-cap-free copy so trends still show a staple that is currently dear.
	 *
	 * @param array<string, mixed> $watch
	 * @return array<string, mixed>
	 */
	private function identityWatch(array $watch): array {
		$copy = $watch;
		unset($copy['max_price'], $copy['max_per_kg']);
		return $copy;
	}

	/**
	 * @param array<string, mixed> $watch
	 * @param list<array<string, mixed>> $offers
	 * @return array<string, mixed>|null
	 */
	private function firstMatchingOffer(array $watch, array $offers, string $storeFilter): ?array {
		foreach ($offers as $offer) {
			if (!is_array($offer)) {
				continue;
			}
			if ($storeFilter !== '' && ($offer['store'] ?? '') !== $storeFilter) {
				continue;
			}
			if ($this->match->matches($watch, $offer)) {
				return $offer;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $offer
	 */
	private function skuFromOffer(array $offer, string $plz): ?string {
		$store = (string)($offer['store'] ?? '');
		$name = trim((string)($offer['name'] ?? ''));
		if ($name === '' || ($store !== 'ALDI Nord' && $store !== 'Lidl')) {
			return null;
		}
		return $this->skuKey(
			$store,
			(string)($offer['brand'] ?? ''),
			$name,
			(string)($offer['pack'] ?? ''),
			$this->plzScope($store, $plz),
		);
	}

	/**
	 * @param list<array<string, mixed>> $offers
	 * @return array<string, array<string, mixed>>
	 */
	private function liveBySku(array $offers, string $plz, string $storeFilter): array {
		$map = [];
		foreach ($offers as $offer) {
			if (!is_array($offer)) {
				continue;
			}
			if ($storeFilter !== '' && ($offer['store'] ?? '') !== $storeFilter) {
				continue;
			}
			$key = $this->skuFromOffer($offer, $plz);
			if ($key === null) {
				continue;
			}
			$map[$key] = $offer;
		}
		return $map;
	}

	/**
	 * Latest-week history rows plus live cache, keyed by sku. Matcher runs in PHP.
	 *
	 * @param array<string, array<string, mixed>> $liveBySku
	 * @return list<array<string, mixed>>
	 */
	private function searchIdentities(string $plz, string $storeFilter, array $liveBySku): array {
		$out = [];
		foreach ($liveBySku as $key => $offer) {
			$out[$key] = [
				'sku_key' => $key,
				'store' => (string)($offer['store'] ?? ''),
				'brand' => (string)($offer['brand'] ?? ''),
				'name' => (string)($offer['name'] ?? ''),
				'pack' => (string)($offer['pack'] ?? ''),
				'price' => $this->money($offer['price'] ?? null),
				'per_kg' => $this->money($offer['per_kg'] ?? null),
			];
		}

		$latest = $this->latestWeekStart($plz);
		if ($latest === null) {
			return array_values($out);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('sku_key', 'store', 'brand', 'name', 'pack', 'price', 'per_kg')
			->from(self::TABLE)
			->where($qb->expr()->eq('week_start', $qb->createNamedParameter($latest)))
			->andWhere($this->scopeExpr($qb, $plz))
			->setMaxResults(self::MAX_IDENTITIES);
		if ($storeFilter !== '') {
			$qb->andWhere($qb->expr()->eq('store', $qb->createNamedParameter($storeFilter)));
		}
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$key = (string)$row['sku_key'];
			if ($key === '' || isset($out[$key])) {
				continue;
			}
			$out[$key] = [
				'sku_key' => $key,
				'store' => (string)$row['store'],
				'brand' => (string)$row['brand'],
				'name' => (string)$row['name'],
				'pack' => (string)$row['pack'],
				'price' => $this->money($row['price'] ?? null),
				'per_kg' => $this->money($row['per_kg'] ?? null),
			];
		}
		$result->closeCursor();
		return array_values($out);
	}

	private function latestWeekStart(string $plz): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('week_start'))
			->from(self::TABLE)
			->where($this->scopeExpr($qb, $plz));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		if (!is_string($value) || $value === '') {
			return null;
		}
		return $value;
	}

	/**
	 * @param list<string> $keys
	 * @return array<string, array<string, mixed>>
	 */
	private function seriesForKeys(array $keys): array {
		$clean = [];
		foreach ($keys as $key) {
			if (is_string($key) && preg_match('/^[a-f0-9]{40}$/', $key) === 1) {
				$clean[$key] = true;
			}
		}
		$clean = array_keys($clean);
		if ($clean === []) {
			return [];
		}
		$oldest = $this->retainCutoff();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->in('sku_key', $qb->createNamedParameter($clean, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('week_start', $qb->createNamedParameter($oldest)))
			->orderBy('week_start', 'ASC')
			->setMaxResults(count($clean) * self::MAX_SERIES);
		$result = $qb->executeQuery();
		$groups = [];
		while ($row = $result->fetch()) {
			$key = (string)$row['sku_key'];
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'sku_key' => $key,
					'store' => (string)$row['store'],
					'brand' => (string)$row['brand'],
					'name' => (string)$row['name'],
					'pack' => (string)$row['pack'],
					'plz' => (string)$row['plz'],
					'points' => [],
					'latest_price' => null,
					'latest_per_kg' => null,
				];
			}
			$groups[$key]['brand'] = (string)$row['brand'];
			$groups[$key]['name'] = (string)$row['name'];
			$groups[$key]['pack'] = (string)$row['pack'];
			$price = $this->money($row['price'] ?? null);
			$perKg = $this->money($row['per_kg'] ?? null);
			$groups[$key]['points'][] = [
				'week_start' => (string)$row['week_start'],
				'price' => $price,
				'per_kg' => $perKg,
			];
			$groups[$key]['latest_price'] = $price;
			$groups[$key]['latest_per_kg'] = $perKg;
		}
		$result->closeCursor();
		return $groups;
	}

	/**
	 * @return array{oldest: ?string, newest: ?string, count: int}
	 */
	private function weekBounds(string $plz): array {
		$oldestCut = $this->retainCutoff();
		$qb = $this->db->getQueryBuilder();
		$qb->select('week_start')
			->from(self::TABLE)
			->where($qb->expr()->gte('week_start', $qb->createNamedParameter($oldestCut)))
			->andWhere($this->scopeExpr($qb, $plz))
			->groupBy('week_start')
			->orderBy('week_start', 'ASC')
			->setMaxResults(self::RETAIN_WEEKS + 4);
		$result = $qb->executeQuery();
		$weeks = [];
		while ($row = $result->fetch()) {
			$w = (string)$row['week_start'];
			if ($w !== '') {
				$weeks[] = $w;
			}
		}
		$result->closeCursor();
		if ($weeks === []) {
			return ['oldest' => null, 'newest' => null, 'count' => 0];
		}
		return [
			'oldest' => $weeks[0],
			'newest' => $weeks[array_key_last($weeks)],
			'count' => count($weeks),
		];
	}

	private function retainCutoff(): string {
		$cutoff = $this->weekStart('current', $this->time->getTime());
		try {
			$cutDt = new \DateTimeImmutable($cutoff, new \DateTimeZone('Europe/Berlin'));
			return $cutDt->modify('-' . self::RETAIN_WEEKS . ' weeks')->format('Y-m-d');
		} catch (\Exception) {
			return '1970-01-01';
		}
	}

	private function scopeExpr(IQueryBuilder $qb, string $plz): \OCP\DB\QueryBuilder\ICompositeExpression {
		return $qb->expr()->orX(
			$qb->expr()->andX(
				$qb->expr()->eq('store', $qb->createNamedParameter('ALDI Nord')),
				$qb->expr()->eq('plz', $qb->createNamedParameter(self::NATIONWIDE)),
			),
			$qb->expr()->andX(
				$qb->expr()->eq('store', $qb->createNamedParameter('Lidl')),
				$qb->expr()->eq('plz', $qb->createNamedParameter($plz)),
			),
		);
	}

	/**
	 * @param array{
	 *   sku_key: string, store: string, brand: string, name: string, pack: string,
	 *   plz: string, week_start: string, price: ?float, per_kg: ?float, recorded_at: int
	 * } $row
	 */
	private function upsert(array $row): void {
		$price = $row['price'] === null ? null : number_format($row['price'], 2, '.', '');
		$perKg = $row['per_kg'] === null ? null : number_format($row['per_kg'], 2, '.', '');
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert(self::TABLE)
				->values([
					'sku_key' => $qb->createNamedParameter($row['sku_key']),
					'store' => $qb->createNamedParameter($row['store']),
					'brand' => $qb->createNamedParameter($row['brand']),
					'name' => $qb->createNamedParameter($row['name']),
					'pack' => $qb->createNamedParameter($row['pack']),
					'plz' => $qb->createNamedParameter($row['plz']),
					'week_start' => $qb->createNamedParameter($row['week_start']),
					'price' => $qb->createNamedParameter($price, IQueryBuilder::PARAM_STR),
					'per_kg' => $qb->createNamedParameter($perKg, IQueryBuilder::PARAM_STR),
					'recorded_at' => $qb->createNamedParameter($row['recorded_at'], IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$up = $this->db->getQueryBuilder();
			$up->update(self::TABLE)
				->set('brand', $up->createNamedParameter($row['brand']))
				->set('name', $up->createNamedParameter($row['name']))
				->set('pack', $up->createNamedParameter($row['pack']))
				->set('price', $up->createNamedParameter($price, IQueryBuilder::PARAM_STR))
				->set('per_kg', $up->createNamedParameter($perKg, IQueryBuilder::PARAM_STR))
				->set('recorded_at', $up->createNamedParameter($row['recorded_at'], IQueryBuilder::PARAM_INT))
				->where($up->expr()->eq('sku_key', $up->createNamedParameter($row['sku_key'])))
				->andWhere($up->expr()->eq('week_start', $up->createNamedParameter($row['week_start'])))
				->executeStatement();
		}
	}

	private function money(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			return null;
		}
		$n = (float)$value;
		if (!is_finite($n) || $n < 0 || $n > 99999.99) {
			return null;
		}
		return round($n, 2);
	}

	private function parseDate(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable {
		$raw = trim($raw);
		if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
			return null;
		}
		try {
			return new \DateTimeImmutable(substr($raw, 0, 10), $tz);
		} catch (\Exception) {
			return null;
		}
	}
}
