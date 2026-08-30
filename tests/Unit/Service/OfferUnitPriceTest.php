<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\OfferUnitPrice;
use PHPUnit\Framework\TestCase;

class OfferUnitPriceTest extends TestCase {
	public function testPrefersStorePerKg(): void {
		$r = OfferUnitPrice::resolve(1.99, 3.98, 2.0, '500 g');
		self::assertSame(3.98, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_KG, $r['unit_kind']);
		self::assertStringContainsString('/kg', $r['unit_label']);
	}

	public function testPrefersPerLWhenNoKg(): void {
		$r = OfferUnitPrice::resolve(0.89, null, 0.89, '1 l');
		self::assertSame(0.89, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_L, $r['unit_kind']);
	}

	public function testDerivesKgFromGrams(): void {
		$r = OfferUnitPrice::resolve(1.0, null, null, '500 g');
		self::assertSame(2.0, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_KG, $r['unit_kind']);
	}

	public function testDerivesLitreFromMl(): void {
		$r = OfferUnitPrice::resolve(1.5, null, null, '750 ml');
		self::assertSame(2.0, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_L, $r['unit_kind']);
	}

	public function testDerivesPiecePriceForEggs(): void {
		$r = OfferUnitPrice::resolve(2.99, null, null, '10 Stück');
		self::assertSame(0.3, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_PC, $r['unit_kind']);
		self::assertStringContainsString('/St.', $r['unit_label']);
	}

	public function testDerivesPieceFromErPack(): void {
		$r = OfferUnitPrice::resolve(1.99, null, null, '6er');
		self::assertSame(0.33, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_PC, $r['unit_kind']);
	}

	public function testDerivesPieceFromXCount(): void {
		$r = OfferUnitPrice::resolve(3.0, null, null, 'x10');
		self::assertSame(0.3, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_PC, $r['unit_kind']);
	}

	public function testRejectsSinglePiece(): void {
		$r = OfferUnitPrice::resolve(1.0, null, null, '1 Stück');
		self::assertNull($r['unit_price']);
		self::assertNull($r['unit_kind']);
	}

	public function testEnrichIsIdempotent(): void {
		$offer = ['price' => 2.0, 'pack' => '1 kg', 'per_kg' => null, 'per_l' => null];
		$once = OfferUnitPrice::enrich($offer);
		$twice = OfferUnitPrice::enrich($once);
		self::assertSame($once['unit_price'], $twice['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_KG, $twice['unit_kind']);
	}

	public function testRejectsNegativeStorePerKg(): void {
		$r = OfferUnitPrice::resolve(1.0, -5.0, null, '');
		self::assertNull($r['unit_price'], 'negative €/kg must not become a unit price');
		self::assertNull($r['unit_kind']);
	}

	public function testRejectsNegativeStorePerL(): void {
		$r = OfferUnitPrice::resolve(1.0, null, -0.5, '');
		self::assertNull($r['unit_price']);
		self::assertNull($r['unit_kind']);
	}

	public function testFallsBackToPackWhenNegativePerKg(): void {
		$r = OfferUnitPrice::resolve(2.0, -1.5, null, '500 g');
		self::assertSame(4.0, $r['unit_price']);
		self::assertSame(OfferUnitPrice::KIND_KG, $r['unit_kind']);
	}

	public function testRejectsTinyGramPackThatWouldExplodeUnitRate(): void {
		$r = OfferUnitPrice::resolve(1.0, null, null, '0.01 g');
		self::assertNull($r['unit_price']);
		self::assertNull($r['unit_kind']);
	}

	public function testRejectsAbsurdDerivedUnitRate(): void {
		// 1 € / 0.001 kg would be 1000 €/kg — still within cap; 1€ / 0.000001 kg blows past.
		$r = OfferUnitPrice::resolve(1.0, null, null, '0.000001 kg');
		self::assertNull($r['unit_price']);
	}
}
