<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Integration;

use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\WatchMatchService;
use PHPUnit\Framework\TestCase;

/**
 * Real-DB price history: upsert, ALDI nationwide key, matcher search.
 */
class PriceHistoryTest extends TestCase {
	private PriceHistoryService $history;
	/** @var list<string> */
	private array $keys = [];

	protected function setUp(): void {
		if (!class_exists(\OC::class)) {
			self::markTestSkipped('Nextcloud bootstrap unavailable');
		}
		$this->history = \OC::$server->get(PriceHistoryService::class);
	}

	protected function tearDown(): void {
		if ($this->keys === [] || !isset($this->history)) {
			return;
		}
		$db = \OC::$server->get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete(PriceHistoryService::TABLE)
			->where($qb->expr()->in('sku_key', $qb->createNamedParameter($this->keys, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
	}

	public function testRecordUpsertsSameWeekAndSharesAldiAcrossPlz(): void {
		$offer = [
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'ekc-hist-milch-xyz',
			'pack' => '1l',
			'price' => 0.89,
			'per_kg' => null,
		];
		$key = $this->history->skuKey(
			'ALDI Nord',
			'Milsani',
			'ekc-hist-milch-xyz',
			'1l',
			$this->history->plzScope('ALDI Nord', '24149'),
		);
		$this->keys[] = $key;

		$n1 = $this->history->record('24149', 'current', [$offer]);
		self::assertSame(1, $n1);
		$offer['price'] = 0.79;
		$n2 = $this->history->record('80331', 'current', [$offer]);
		self::assertSame(1, $n2);

		$db = \OC::$server->get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('price', 'plz')
			->from(PriceHistoryService::TABLE)
			->where($qb->expr()->eq('sku_key', $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		self::assertCount(1, $rows);
		self::assertSame('*', (string)$rows[0]['plz']);
		self::assertEqualsWithDelta(0.79, (float)$rows[0]['price'], 0.001);
	}

	public function testLidlPlzDoesNotLeakAndEisDoesNotMatchReis(): void {
		$reis = [
			'store' => 'Lidl',
			'brand' => '',
			'name' => 'ekc-hist-reis-xyz',
			'pack' => '1kg',
			'price' => 1.19,
			'per_kg' => 1.19,
		];
		$key24149 = $this->history->skuKey('Lidl', '', 'ekc-hist-reis-xyz', '1kg', '24149');
		$key80331 = $this->history->skuKey('Lidl', '', 'ekc-hist-reis-xyz', '1kg', '80331');
		$this->keys[] = $key24149;
		$this->keys[] = $key80331;
		self::assertNotSame($key24149, $key80331);

		$this->history->record('24149', 'current', [$reis]);
		$reis['price'] = 2.49;
		$this->history->record('80331', 'current', [$reis]);

		$out = $this->history->summarize('24149', 'current', [$reis], [], 'eis', '');
		self::assertSame([], $out['search']);

		$hit = $this->history->summarize('24149', 'current', [
			array_merge($reis, ['price' => 1.19]),
		], [], 'reis', '');
		self::assertNotEmpty($hit['search']);
		self::assertSame('ekc-hist-reis-xyz', $hit['search'][0]['name']);

		$match = new WatchMatchService();
		self::assertFalse($match->matches(
			['query' => 'eis', 'enabled' => true],
			['store' => 'Lidl', 'brand' => '', 'name' => 'ekc-hist-reis-xyz', 'price' => 1.19],
		));
	}

	public function testOneWeekIsNewNotCheap(): void {
		$offer = [
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'ekc-hist-sahne-xyz',
			'pack' => '200g',
			'price' => 0.66,
			'per_kg' => 3.3,
		];
		$key = $this->history->skuKey('ALDI Nord', 'Milsani', 'ekc-hist-sahne-xyz', '200g', '*');
		$this->keys[] = $key;
		$this->history->record('24149', 'current', [$offer]);
		$out = $this->history->summarize('24149', 'current', [$offer], [], '', '');
		self::assertSame([], $out['cheap_now']);
		$watchOut = $this->history->summarize('24149', 'current', [$offer], [
			['query' => 'sahne', 'enabled' => true, 'store' => ''],
		], '', '');
		self::assertNotEmpty($watchOut['staples']);
		self::assertSame('new', $watchOut['staples'][0]['verdict']);

		$capped = $this->history->summarize('24149', 'current', [$offer], [
			['query' => 'sahne', 'enabled' => true, 'store' => '', 'max_price' => 0.01],
		], '', '');
		self::assertNotEmpty($capped['staples']);
	}

	public function testDropAgainstPriorWeekIsCheap(): void {
		$current = $this->history->weekStart('current', time());
		$prev = (new \DateTimeImmutable($current, new \DateTimeZone('Europe/Berlin')))
			->modify('-7 days')
			->format('Y-m-d');
		$offer = [
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'ekc-hist-butter-xyz',
			'pack' => '250g',
			'price' => 1.29,
			'per_kg' => null,
		];
		$key = $this->history->skuKey('ALDI Nord', 'Milsani', 'ekc-hist-butter-xyz', '250g', '*');
		$this->keys[] = $key;
		$this->history->record('24149', 'current', [array_merge($offer, ['valid_from' => $prev, 'price' => 1.29])]);
		$live = array_merge($offer, ['valid_from' => $current, 'price' => 0.99]);
		$this->history->record('24149', 'current', [$live]);
		$out = $this->history->summarize('24149', 'current', [$live], [], '', '');
		self::assertNotEmpty($out['cheap_now']);
		self::assertSame('cheap', $out['cheap_now'][0]['verdict']);
		self::assertSame('ekc-hist-butter-xyz', $out['cheap_now'][0]['name']);
	}
}
