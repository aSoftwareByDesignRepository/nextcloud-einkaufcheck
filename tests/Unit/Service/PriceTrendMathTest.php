<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\PriceTrendMath;
use PHPUnit\Framework\TestCase;

class PriceTrendMathTest extends TestCase {
	public function testSingleSnapshotIsNewNotCheap(): void {
		$out = PriceTrendMath::classify(0.89, []);
		self::assertSame('new', $out['verdict']);
		self::assertNull($out['drop_pct']);
	}

	public function testNullCurrentIsUnknown(): void {
		$out = PriceTrendMath::classify(null, [1.0, 1.1]);
		self::assertSame('unknown', $out['verdict']);
	}

	public function testEightPercentAndFiveCentsIsCheap(): void {
		$out = PriceTrendMath::classify(0.92, [1.00]);
		self::assertSame('cheap', $out['verdict']);
		self::assertSame(8.0, $out['drop_pct']);
	}

	public function testEightPercentButUnderFiveCentsIsNotCheap(): void {
		$out = PriceTrendMath::classify(0.46, [0.50]);
		self::assertSame('usual', $out['verdict']);
	}

	public function testUniqueLowestWithTenCentsIsCheap(): void {
		$out = PriceTrendMath::classify(1.00, [1.10]);
		self::assertSame('cheap', $out['verdict']);
	}

	public function testUniqueLowestWithNineCentsButWeakPercentIsUsual(): void {
		$out = PriceTrendMath::classify(2.00, [2.09]);
		self::assertSame('usual', $out['verdict']);
	}

	public function testEightPercentHigherIsDear(): void {
		$out = PriceTrendMath::classify(1.08, [1.00]);
		self::assertSame('dear', $out['verdict']);
	}

	public function testTinyMoveIsUsual(): void {
		$out = PriceTrendMath::classify(0.99, [1.00]);
		self::assertSame('usual', $out['verdict']);
	}

	public function testSeriesPrefersKgOnlyWhenEveryPointHasIt(): void {
		$all = PriceTrendMath::seriesMetric([
			['price' => 1.0, 'per_kg' => 2.0],
			['price' => 1.1, 'per_kg' => 2.2],
		]);
		self::assertSame('kg', $all['unit']);
		self::assertSame([2.0, 2.2], $all['values']);

		$mixed = PriceTrendMath::seriesMetric([
			['price' => 1.0, 'per_kg' => 2.0],
			['price' => 1.1, 'per_kg' => null],
		]);
		self::assertSame('pack', $mixed['unit']);
		self::assertSame([1.0, 1.1], $mixed['values']);
	}

	public function testEmptySeriesIsPack(): void {
		$out = PriceTrendMath::seriesMetric([]);
		self::assertSame('pack', $out['unit']);
		self::assertSame([], $out['values']);
	}
}
