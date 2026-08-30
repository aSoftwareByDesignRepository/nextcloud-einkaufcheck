<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\WatchMatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use PHPUnit\Framework\TestCase;

class WeekCompareServiceTest extends TestCase {
	private WeekCompareService $svc;

	protected function setUp(): void {
		$this->svc = new WeekCompareService(new WatchMatchService());
	}

	public function testCheaperLaterWhenOtherWeekLower(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.19,
			'per_l' => 1.19,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 0.89,
			'per_l' => 0.89,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		self::assertSame('cheaper_later', $out[0]['week_tip']['verdict']);
		self::assertSame('next', $out[0]['week_tip']['other_week']);
		self::assertEqualsWithDelta(0.3, $out[0]['week_tip']['saving'], 0.001);
	}

	public function testCheaperNowWhenThisWeekLower(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 0.79,
			'per_l' => 0.79,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.19,
			'per_l' => 1.19,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		self::assertSame('cheaper_now', $out[0]['week_tip']['verdict']);
	}

	public function testNoTipWhenDeltaTiny(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.00,
			'per_l' => 1.00,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.02,
			'per_l' => 1.02,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		self::assertNull($out[0]['week_tip']);
	}

	public function testNoTipOnKindMismatch(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => '',
			'name' => 'Eier',
			'pack' => '10 Stück',
			'price' => 2.99,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => '',
			'name' => 'Eier',
			'pack' => '10 Stück',
			'price' => 2.50,
			'per_kg' => 5.0,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		// now → pc, next → kg (store per_kg wins) → no tip
		self::assertNull($out[0]['week_tip']);
	}

	public function testDifferentPacksAreNotCompared(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.19,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1.5 l',
			'price' => 0.50,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		self::assertNull($out[0]['week_tip']);
	}

	public function testPicksCheapestOtherWeekRow(): void {
		$now = [[
			'store' => 'Lidl',
			'brand' => 'Milbona',
			'name' => 'Sahne',
			'pack' => '200 g',
			'price' => 0.79,
			'per_kg' => 3.95,
		]];
		$other = [
			[
				'store' => 'Lidl',
				'brand' => 'Milbona',
				'name' => 'Sahne',
				'pack' => '200 g',
				'price' => 0.99,
				'per_kg' => 4.95,
			],
			[
				'store' => 'Lidl',
				'brand' => 'Milbona',
				'name' => 'Sahne',
				'pack' => '200 g',
				'price' => 0.59,
				'per_kg' => 2.95,
			],
		];
		$out = $this->svc->annotate($now, $other, 'next', '24149');
		self::assertSame('cheaper_later', $out[0]['week_tip']['verdict']);
		self::assertEqualsWithDelta(1.0, $out[0]['week_tip']['saving'], 0.001);
	}

	public function testNegativePerKgDoesNotDriveWeekTip(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.19,
			'per_l' => 1.19,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 0.89,
			'per_l' => -9.99,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		// Negative per_l ignored → pack price 0.89 vs 1.19 → tip still ok on pack kind
		self::assertSame('cheaper_later', $out[0]['week_tip']['verdict'] ?? null);
		self::assertGreaterThan(0, $out[0]['week_tip']['saving'] ?? 0);
	}

	public function testNegativePackPriceDoesNotDriveWeekTip(): void {
		$now = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.19,
		]];
		$next = [[
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => -0.01,
		]];
		$out = $this->svc->annotate($now, $next, 'next', '24149');
		self::assertNull($out[0]['week_tip'] ?? null, 'negative pack price must not invent a tip');
	}
}
