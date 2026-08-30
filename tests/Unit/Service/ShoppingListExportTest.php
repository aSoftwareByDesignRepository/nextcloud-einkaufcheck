<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\ShoppingListService;
use PHPUnit\Framework\TestCase;

class ShoppingListExportTest extends TestCase {
	public function testNormalizeStoreFilterAcceptsAldiLidlAll(): void {
		self::assertSame('', ShoppingListService::normalizeStoreFilter(''));
		self::assertSame('', ShoppingListService::normalizeStoreFilter('all'));
		self::assertSame('ALDI Nord', ShoppingListService::normalizeStoreFilter('ALDI Nord'));
		self::assertSame('Lidl', ShoppingListService::normalizeStoreFilter('Lidl'));
	}

	public function testNormalizeStoreFilterRejectsOtherChains(): void {
		$this->expectException(ValidationException::class);
		ShoppingListService::normalizeStoreFilter('REWE');
	}

	public function testFormatExportGroupsByStoreForTheSplitTrip(): void {
		$export = ShoppingListService::formatExport([
			['store' => 'Lidl', 'brand' => '', 'name' => 'Bananen', 'pack' => 'kg', 'qty' => 1, 'price' => 1.19, 'per_kg' => 1.19, 'note' => '', 'checked' => false],
			['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'Milch', 'pack' => '1 l', 'qty' => 2, 'price' => 0.89, 'per_kg' => null, 'note' => '', 'checked' => false],
		], '');
		self::assertStringContainsString("Einkaufszettel\nALDI Nord\n", $export['text']);
		self::assertStringContainsString("Lidl\n☐ 1x Bananen", $export['text']);
		self::assertStringContainsString('☐ 2x Milsani Milch', $export['text']);
		self::assertStringStartsWith('https://wa.me/?text=', $export['whatsapp_url']);
		self::assertCount(2, $export['items']);
	}

	public function testFormatExportAldiOnlyOmitsLidlLines(): void {
		$aldi = ['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'Milch', 'pack' => '', 'qty' => 1, 'price' => 0.89, 'per_kg' => null, 'note' => '', 'checked' => false];
		$lidl = ['store' => 'Lidl', 'brand' => '', 'name' => 'Bananen', 'pack' => '', 'qty' => 1, 'price' => 1.19, 'per_kg' => null, 'note' => '', 'checked' => false];
		$export = ShoppingListService::formatExport([$aldi], 'ALDI Nord');
		self::assertStringStartsWith('Einkaufszettel — ALDI Nord', $export['text']);
		self::assertStringContainsString('Milch', $export['text']);
		self::assertStringNotContainsString('Bananen', $export['text']);
		$both = ShoppingListService::formatExport([$aldi, $lidl], '');
		self::assertStringContainsString('Bananen', $both['text']);
	}

	public function testFormatExportCsvStillNeutralizesFormulas(): void {
		$export = ShoppingListService::formatExport([
			['store' => 'Lidl', 'brand' => '', 'name' => '=CMD()', 'pack' => '', 'qty' => 1, 'price' => null, 'per_kg' => null, 'note' => '+1+1', 'checked' => false],
		], 'Lidl');
		self::assertStringContainsString("\"'=CMD()\"", $export['csv']);
		self::assertStringContainsString("\"'+1+1\"", $export['csv']);
	}
}
