<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Integration;

use OCA\EinkaufCheck\Exception\NotFoundException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use PHPUnit\Framework\TestCase;

/**
 * Real-DB ownership, IDOR, and validation. Requires Nextcloud bootstrap.
 */
class OwnershipAndValidationTest extends TestCase {
	private ShoppingListService $list;
	private WatchService $watch;

	protected function setUp(): void {
		if (!class_exists(\OC::class)) {
			self::markTestSkipped('Nextcloud bootstrap unavailable');
		}
		$this->list = \OC::$server->get(ShoppingListService::class);
		$this->watch = \OC::$server->get(WatchService::class);
	}

	public function testVictimCannotReadOrMutateAdminListItem(): void {
		$adminItem = $this->list->add('admin', ['name' => 'Admin secret milk', 'qty' => 1]);
		$adminId = (int)$adminItem['id'];
		try {
			$victimItems = $this->list->list('victim');
			foreach ($victimItems as $row) {
				self::assertNotSame($adminId, (int)$row['id']);
				self::assertNotSame('Admin secret milk', $row['name']);
			}
			$this->expectException(NotFoundException::class);
			$this->list->update('victim', $adminId, ['qty' => 9]);
		} finally {
			$this->list->delete('admin', $adminId);
		}
	}

	public function testVictimDeleteOfAdminItemDoesNotRemoveIt(): void {
		$adminItem = $this->list->add('admin', ['name' => 'Do not delete', 'qty' => 1]);
		$adminId = (int)$adminItem['id'];
		try {
			try {
				$this->list->delete('victim', $adminId);
				self::fail('Deleting another user\'s item must throw NotFoundException');
			} catch (NotFoundException) {
				// expected
			}
			$still = $this->list->list('admin');
			$ids = array_map(static fn (array $r): int => (int)$r['id'], $still);
			self::assertContains($adminId, $ids);
		} finally {
			$this->list->delete('admin', $adminId);
		}
	}

	public function testWatchIdorSameAsList(): void {
		$w = $this->watch->add('admin', ['query' => 'secret-staple-xyz']);
		$id = (int)$w['id'];
		try {
			$this->expectException(NotFoundException::class);
			$this->watch->update('victim', $id, ['query' => 'hijacked']);
		} finally {
			try {
				$this->watch->delete('admin', $id);
			} catch (\Throwable) {
			}
		}
	}

	public function testPayloadUserIdCannotReassignOwnership(): void {
		$item = $this->list->add('admin', [
			'name' => 'owned',
			'qty' => 1,
			'user_id' => 'victim',
		]);
		try {
			self::assertSame('owned', $item['name']);
			$adminIds = array_map(static fn (array $r): int => (int)$r['id'], $this->list->list('admin'));
			$victimIds = array_map(static fn (array $r): int => (int)$r['id'], $this->list->list('victim'));
			self::assertContains((int)$item['id'], $adminIds);
			self::assertNotContains((int)$item['id'], $victimIds);
		} finally {
			$this->list->delete('admin', (int)$item['id']);
		}
	}

	public function testQtyRejectsNonIntegersAndOutOfRange(): void {
		$this->expectException(ValidationException::class);
		$this->list->add('admin', ['name' => 'x', 'qty' => 1.5]);
	}

	public function testQty99OkQty100Rejected(): void {
		$ok = $this->list->add('admin', ['name' => 'cap-ok', 'qty' => 99]);
		try {
			self::assertSame(99, $ok['qty']);
			$this->expectException(ValidationException::class);
			$this->list->add('admin', ['name' => 'cap-bad', 'qty' => 100]);
		} finally {
			$this->list->delete('admin', (int)$ok['id']);
		}
	}

	public function testDeleteMissingThrowsNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->list->delete('admin', 2147483646);
	}

	public function testWatchQueryShorterThanThreeCharsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->watch->add('admin', ['query' => 'ab']);
	}

	public function testWatchQueryOneCharWouldMatchAlmostEverythingIfAllowed(): void {
		$this->expectException(ValidationException::class);
		$this->watch->add('admin', ['query' => 'e']);
	}

	public function testCheckedFalseStringUnchecksItem(): void {
		$item = $this->list->add('admin', ['name' => 'bool-trap', 'qty' => 1]);
		$id = (int)$item['id'];
		try {
			$checked = $this->list->update('admin', $id, ['checked' => true]);
			self::assertTrue($checked['checked']);
			$unchecked = $this->list->update('admin', $id, ['checked' => 'false']);
			self::assertFalse($unchecked['checked']);
		} finally {
			$this->list->delete('admin', $id);
		}
	}

	public function testWatchEnabledFalseStringDisables(): void {
		$w = $this->watch->add('admin', ['query' => 'bool-watch-xyz']);
		$id = (int)$w['id'];
		try {
			self::assertTrue($w['enabled']);
			$upd = $this->watch->update('admin', $id, ['enabled' => 'false']);
			self::assertFalse($upd['enabled']);
		} finally {
			$this->watch->delete('admin', $id);
		}
	}

	public function testGarbageEnabledRejected(): void {
		$w = $this->watch->add('admin', ['query' => 'bool-watch-bad']);
		$id = (int)$w['id'];
		try {
			$this->expectException(ValidationException::class);
			$this->watch->update('admin', $id, ['enabled' => 'maybe']);
		} finally {
			try {
				$this->watch->delete('admin', $id);
			} catch (\Throwable) {
			}
		}
	}

	public function testPartialUpdateOmittingCheckedKeepsChecked(): void {
		$item = $this->list->add('admin', ['name' => 'keep-checked', 'qty' => 1]);
		$id = (int)$item['id'];
		try {
			$this->list->update('admin', $id, ['checked' => true]);
			$upd = $this->list->update('admin', $id, ['qty' => 2]);
			self::assertTrue($upd['checked']);
			self::assertSame(2, $upd['qty']);
		} finally {
			$this->list->delete('admin', $id);
		}
	}

	public function testNameIsStoredLiterallyNotExecutedAsSql(): void {
		$payload = "x'; DROP TABLE oc_einkaufcheck_items; --";
		$item = $this->list->add('admin', ['name' => $payload, 'qty' => 1]);
		$id = (int)$item['id'];
		try {
			self::assertSame($payload, $item['name']);
			$again = $this->list->list('admin');
			$names = array_map(static fn (array $r): string => (string)$r['name'], $again);
			self::assertContains($payload, $names);
		} finally {
			$this->list->delete('admin', $id);
		}
	}

	public function testCsvExportNeutralizesFormulaInjection(): void {
		$item = $this->list->add('admin', ['name' => '=CMD()', 'qty' => 1, 'note' => '+1+1']);
		$id = (int)$item['id'];
		try {
			$export = $this->list->export('admin');
			self::assertStringContainsString("\"'=CMD()\"", $export['csv']);
			self::assertStringContainsString("\"'+1+1\"", $export['csv']);
			self::assertDoesNotMatchRegularExpression('/^[=+\-@]/m', str_replace('"', '', $export['csv']));
		} finally {
			$this->list->delete('admin', $id);
		}
	}

	public function testExportCanFilterToOneStore(): void {
		$aldi = $this->list->add('admin', ['name' => 'EKC store-filter milk', 'store' => 'ALDI Nord', 'qty' => 1]);
		$lidl = $this->list->add('admin', ['name' => 'EKC store-filter bananas', 'store' => 'Lidl', 'qty' => 1]);
		try {
			$aldiOnly = $this->list->export('admin', 'ALDI Nord');
			self::assertStringStartsWith('Einkaufszettel — ALDI Nord', $aldiOnly['text']);
			self::assertStringContainsString('EKC store-filter milk', $aldiOnly['text']);
			self::assertStringNotContainsString('EKC store-filter bananas', $aldiOnly['text']);
			$lidlOnly = $this->list->export('admin', 'Lidl');
			self::assertStringContainsString('EKC store-filter bananas', $lidlOnly['text']);
			self::assertStringNotContainsString('EKC store-filter milk', $lidlOnly['text']);
		} finally {
			$this->list->delete('admin', (int)$aldi['id']);
			$this->list->delete('admin', (int)$lidl['id']);
		}
	}

	public function testClearCanFilterToOneStore(): void {
		$aldi = $this->list->add('admin', ['name' => 'EKC clear-filter milk', 'store' => 'ALDI Nord', 'qty' => 1]);
		$lidl = $this->list->add('admin', ['name' => 'EKC clear-filter bananas', 'store' => 'Lidl', 'qty' => 1]);
		try {
			$this->list->clear('admin', 'ALDI Nord');
			$names = array_map(static fn (array $r): string => (string)$r['name'], $this->list->list('admin'));
			self::assertNotContains('EKC clear-filter milk', $names);
			self::assertContains('EKC clear-filter bananas', $names);
		} finally {
			try {
				$this->list->delete('admin', (int)$lidl['id']);
			} catch (NotFoundException) {
			}
			try {
				$this->list->delete('admin', (int)$aldi['id']);
			} catch (NotFoundException) {
			}
		}
	}

	public function testClaimHitKeySecondCallerLoses(): void {
		$w = $this->watch->add('admin', ['query' => 'claim-race-xyz']);
		$id = (int)$w['id'];
		try {
			self::assertTrue($this->watch->claimHitKey('admin', $id, '', 'new-key-aaa'));
			self::assertFalse($this->watch->claimHitKey('admin', $id, '', 'new-key-bbb'));
			$rows = $this->watch->list('admin');
			$mine = null;
			foreach ($rows as $row) {
				if ((int)$row['id'] === $id) {
					$mine = $row;
					break;
				}
			}
			self::assertNotNull($mine);
			self::assertSame('new-key-aaa', $mine['last_hit_key']);
		} finally {
			$this->watch->delete('admin', $id);
		}
	}

	public function testAddingSameUncheckedOfferIncrementsQty(): void {
		$first = $this->list->add('admin', [
			'name' => 'merge-milk',
			'brand' => 'Milsani',
			'store' => 'ALDI Nord',
			'pack' => '1 l',
			'price' => 0.89,
			'qty' => 1,
		]);
		$id = (int)$first['id'];
		try {
			$second = $this->list->add('admin', [
				'name' => 'merge-milk',
				'brand' => 'Milsani',
				'store' => 'ALDI Nord',
				'pack' => '1 l',
				'price' => 0.89,
				'qty' => 2,
			]);
			self::assertSame($id, (int)$second['id']);
			self::assertSame(3, $second['qty']);
			$names = array_values(array_filter(
				$this->list->list('admin'),
				static fn (array $r): bool => $r['name'] === 'merge-milk',
			));
			self::assertCount(1, $names);
		} finally {
			$this->list->delete('admin', $id);
		}
	}

	public function testAddingSameOfferDoesNotMergeCheckedLine(): void {
		$first = $this->list->add('admin', [
			'name' => 'merge-checked',
			'store' => 'Lidl',
			'qty' => 1,
		]);
		$id = (int)$first['id'];
		$extraId = null;
		try {
			$this->list->update('admin', $id, ['checked' => true]);
			$second = $this->list->add('admin', [
				'name' => 'merge-checked',
				'store' => 'Lidl',
				'qty' => 1,
			]);
			$extraId = (int)$second['id'];
			self::assertNotSame($id, $extraId);
			self::assertSame(1, $second['qty']);
			self::assertFalse($second['checked']);
		} finally {
			$this->list->delete('admin', $id);
			if ($extraId !== null) {
				$this->list->delete('admin', $extraId);
			}
		}
	}
}
