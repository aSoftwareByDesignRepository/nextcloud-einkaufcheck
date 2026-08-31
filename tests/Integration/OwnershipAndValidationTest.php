<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Integration;

use OCA\EinkaufCheck\Exception\NotFoundException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

/**
 * Real-DB workspace ownership, IDOR, and validation. Requires Nextcloud bootstrap.
 */
class OwnershipAndValidationTest extends TestCase {
	use PurgesSoleOwnedWorkspaces;

	private ShoppingListService $list;
	private WatchService $watch;
	private WorkspaceService $workspaces;
	private int $spaceA = 0;
	private int $spaceB = 0;

	protected function setUp(): void {
		if (!class_exists(\OC::class)) {
			self::markTestSkipped('Nextcloud bootstrap unavailable');
		}
		$this->list = \OC::$server->get(ShoppingListService::class);
		$this->watch = \OC::$server->get(WatchService::class);
		$this->workspaces = \OC::$server->get(WorkspaceService::class);
		$this->ensureWorkspaceCreateHeadroom('admin');
		$admin = $this->workspaces->ensurePersonalWorkspace('admin');
		$this->spaceA = (int)$admin['id'];
		$other = $this->workspaces->createWorkspace(
			'admin',
			'IDOR isolation ' . bin2hex(random_bytes(3)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$this->spaceB = (int)$other['id'];
	}

	protected function tearDown(): void {
		if ($this->spaceB > 0 && isset($this->workspaces)) {
			try {
				$this->workspaces->deleteWorkspace($this->spaceB, 'admin');
			} catch (\Throwable) {
				// space may already be gone if the test deleted it
			}
			$this->spaceB = 0;
		}
	}

	public function testCrossWorkspaceCannotReadOrMutateItems(): void {
		$adminItem = $this->list->add($this->spaceA, 'admin', ['name' => 'Admin secret milk', 'qty' => 1]);
		$adminId = (int)$adminItem['id'];
		try {
			$victimItems = $this->list->list($this->spaceB, 'admin');
			foreach ($victimItems as $row) {
				self::assertNotSame($adminId, (int)$row['id']);
				self::assertNotSame('Admin secret milk', $row['name']);
			}
			$this->expectException(NotFoundException::class);
			$this->list->update($this->spaceB, 'admin', $adminId, ['qty' => 9]);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $adminId);
		}
	}

	public function testCrossWorkspaceDeleteDoesNotRemoveItem(): void {
		$adminItem = $this->list->add($this->spaceA, 'admin', ['name' => 'Do not delete', 'qty' => 1]);
		$adminId = (int)$adminItem['id'];
		try {
			try {
				$this->list->delete($this->spaceB, 'admin', $adminId);
				self::fail('Deleting another space\'s item must throw NotFoundException');
			} catch (NotFoundException) {
				// expected
			}
			$still = $this->list->list($this->spaceA, 'admin');
			$ids = array_map(static fn (array $r): int => (int)$r['id'], $still);
			self::assertContains($adminId, $ids);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $adminId);
		}
	}

	public function testWatchIdorSameAsList(): void {
		$w = $this->watch->add($this->spaceA, 'admin', ['query' => 'secret-staple-xyz']);
		$id = (int)$w['id'];
		try {
			$this->expectException(NotFoundException::class);
			$this->watch->update($this->spaceB, 'admin', $id, ['query' => 'hijacked']);
		} finally {
			try {
				$this->watch->delete($this->spaceA, 'admin', $id);
			} catch (\Throwable) {
			}
		}
	}

	public function testPayloadUserIdCannotReassignOwnership(): void {
		$item = $this->list->add($this->spaceA, 'admin', [
			'name' => 'owned',
			'qty' => 1,
			'user_id' => 'victim',
		]);
		try {
			self::assertSame('owned', $item['name']);
			$adminIds = array_map(static fn (array $r): int => (int)$r['id'], $this->list->list($this->spaceA, 'admin'));
			$victimIds = array_map(static fn (array $r): int => (int)$r['id'], $this->list->list($this->spaceB, 'admin'));
			self::assertContains((int)$item['id'], $adminIds);
			self::assertNotContains((int)$item['id'], $victimIds);
		} finally {
			$this->list->delete($this->spaceA, 'admin', (int)$item['id']);
		}
	}

	public function testQtyRejectsNonIntegersAndOutOfRange(): void {
		$this->expectException(ValidationException::class);
		$this->list->add($this->spaceA, 'admin', ['name' => 'x', 'qty' => 1.5]);
	}

	public function testQty99OkQty100Rejected(): void {
		$ok = $this->list->add($this->spaceA, 'admin', ['name' => 'cap-ok', 'qty' => 99]);
		try {
			self::assertSame(99, $ok['qty']);
			$this->expectException(ValidationException::class);
			$this->list->add($this->spaceA, 'admin', ['name' => 'cap-bad', 'qty' => 100]);
		} finally {
			$this->list->delete($this->spaceA, 'admin', (int)$ok['id']);
		}
	}

	public function testDeleteMissingThrowsNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->list->delete($this->spaceA, 'admin', 2147483646);
	}

	public function testWatchQueryShorterThanThreeCharsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->watch->add($this->spaceA, 'admin', ['query' => 'ab']);
	}

	public function testWatchQueryOneCharWouldMatchAlmostEverythingIfAllowed(): void {
		$this->expectException(ValidationException::class);
		$this->watch->add($this->spaceA, 'admin', ['query' => 'e']);
	}

	public function testCheckedFalseStringUnchecksItem(): void {
		$item = $this->list->add($this->spaceA, 'admin', ['name' => 'bool-trap', 'qty' => 1]);
		$id = (int)$item['id'];
		try {
			$checked = $this->list->update($this->spaceA, 'admin', $id, ['checked' => true]);
			self::assertTrue($checked['checked']);
			$unchecked = $this->list->update($this->spaceA, 'admin', $id, ['checked' => 'false']);
			self::assertFalse($unchecked['checked']);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testWatchEnabledFalseStringDisables(): void {
		$w = $this->watch->add($this->spaceA, 'admin', ['query' => 'bool-watch-xyz']);
		$id = (int)$w['id'];
		try {
			self::assertTrue($w['enabled']);
			$upd = $this->watch->update($this->spaceA, 'admin', $id, ['enabled' => 'false']);
			self::assertFalse($upd['enabled']);
		} finally {
			$this->watch->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testGarbageEnabledRejected(): void {
		$w = $this->watch->add($this->spaceA, 'admin', ['query' => 'bool-watch-bad']);
		$id = (int)$w['id'];
		try {
			$this->expectException(ValidationException::class);
			$this->watch->update($this->spaceA, 'admin', $id, ['enabled' => 'maybe']);
		} finally {
			try {
				$this->watch->delete($this->spaceA, 'admin', $id);
			} catch (\Throwable) {
			}
		}
	}

	public function testPartialUpdateOmittingCheckedKeepsChecked(): void {
		$item = $this->list->add($this->spaceA, 'admin', ['name' => 'keep-checked', 'qty' => 1]);
		$id = (int)$item['id'];
		try {
			$this->list->update($this->spaceA, 'admin', $id, ['checked' => true]);
			$upd = $this->list->update($this->spaceA, 'admin', $id, ['qty' => 2]);
			self::assertTrue($upd['checked']);
			self::assertSame(2, $upd['qty']);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testNameIsStoredLiterallyNotExecutedAsSql(): void {
		$payload = "x'; DROP TABLE oc_einkaufcheck_items; --";
		$item = $this->list->add($this->spaceA, 'admin', ['name' => $payload, 'qty' => 1]);
		$id = (int)$item['id'];
		try {
			self::assertSame($payload, $item['name']);
			$again = $this->list->list($this->spaceA, 'admin');
			$names = array_map(static fn (array $r): string => (string)$r['name'], $again);
			self::assertContains($payload, $names);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testCsvExportNeutralizesFormulaInjection(): void {
		$item = $this->list->add($this->spaceA, 'admin', ['name' => '=CMD()', 'qty' => 1, 'note' => '+1+1']);
		$id = (int)$item['id'];
		try {
			$export = $this->list->export($this->spaceA, 'admin');
			self::assertStringContainsString("\"'=CMD()\"", $export['csv']);
			self::assertStringContainsString("\"'+1+1\"", $export['csv']);
			self::assertDoesNotMatchRegularExpression('/^[=+\-@]/m', str_replace('"', '', $export['csv']));
		} finally {
			$this->list->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testExportCanFilterToOneStore(): void {
		$aldi = $this->list->add($this->spaceA, 'admin', ['name' => 'EKC store-filter milk', 'store' => 'ALDI Nord', 'qty' => 1]);
		$lidl = $this->list->add($this->spaceA, 'admin', ['name' => 'EKC store-filter bananas', 'store' => 'Lidl', 'qty' => 1]);
		try {
			$aldiOnly = $this->list->export($this->spaceA, 'admin', 'ALDI Nord');
			self::assertStringStartsWith('Einkaufszettel — ALDI Nord', $aldiOnly['text']);
			self::assertStringContainsString('EKC store-filter milk', $aldiOnly['text']);
			self::assertStringNotContainsString('EKC store-filter bananas', $aldiOnly['text']);
			$lidlOnly = $this->list->export($this->spaceA, 'admin', 'Lidl');
			self::assertStringContainsString('EKC store-filter bananas', $lidlOnly['text']);
			self::assertStringNotContainsString('EKC store-filter milk', $lidlOnly['text']);
		} finally {
			$this->list->delete($this->spaceA, 'admin', (int)$aldi['id']);
			$this->list->delete($this->spaceA, 'admin', (int)$lidl['id']);
		}
	}

	public function testClearCanFilterToOneStore(): void {
		$aldi = $this->list->add($this->spaceA, 'admin', ['name' => 'EKC clear-filter milk', 'store' => 'ALDI Nord', 'qty' => 1]);
		$lidl = $this->list->add($this->spaceA, 'admin', ['name' => 'EKC clear-filter bananas', 'store' => 'Lidl', 'qty' => 1]);
		try {
			$this->list->clear($this->spaceA, 'admin', 'ALDI Nord');
			$names = array_map(static fn (array $r): string => (string)$r['name'], $this->list->list($this->spaceA, 'admin'));
			self::assertNotContains('EKC clear-filter milk', $names);
			self::assertContains('EKC clear-filter bananas', $names);
		} finally {
			try {
				$this->list->delete($this->spaceA, 'admin', (int)$lidl['id']);
			} catch (NotFoundException) {
			}
			try {
				$this->list->delete($this->spaceA, 'admin', (int)$aldi['id']);
			} catch (NotFoundException) {
			}
		}
	}

	public function testClaimHitKeySecondCallerLoses(): void {
		$w = $this->watch->add($this->spaceA, 'admin', ['query' => 'claim-race-xyz']);
		$id = (int)$w['id'];
		try {
			self::assertTrue($this->watch->claimHitKey($this->spaceA, $id, '', 'new-key-aaa'));
			self::assertFalse($this->watch->claimHitKey($this->spaceA, $id, '', 'new-key-bbb'));
			$rows = $this->watch->list($this->spaceA, 'admin');
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
			$this->watch->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testAddingSameUncheckedOfferIncrementsQty(): void {
		$first = $this->list->add($this->spaceA, 'admin', [
			'name' => 'merge-milk',
			'brand' => 'Milsani',
			'store' => 'ALDI Nord',
			'pack' => '1 l',
			'price' => 0.89,
			'qty' => 1,
		]);
		$id = (int)$first['id'];
		try {
			$second = $this->list->add($this->spaceA, 'admin', [
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
				$this->list->list($this->spaceA, 'admin'),
				static fn (array $r): bool => $r['name'] === 'merge-milk',
			));
			self::assertCount(1, $names);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $id);
		}
	}

	public function testAddingSameOfferDoesNotMergeCheckedLine(): void {
		$first = $this->list->add($this->spaceA, 'admin', [
			'name' => 'merge-checked',
			'store' => 'Lidl',
			'qty' => 1,
		]);
		$id = (int)$first['id'];
		$extraId = null;
		try {
			$this->list->update($this->spaceA, 'admin', $id, ['checked' => true]);
			$second = $this->list->add($this->spaceA, 'admin', [
				'name' => 'merge-checked',
				'store' => 'Lidl',
				'qty' => 1,
			]);
			$extraId = (int)$second['id'];
			self::assertNotSame($id, $extraId);
			self::assertSame(1, $second['qty']);
			self::assertFalse($second['checked']);
		} finally {
			$this->list->delete($this->spaceA, 'admin', $id);
			if ($extraId !== null) {
				$this->list->delete($this->spaceA, 'admin', $extraId);
			}
		}
	}
}
