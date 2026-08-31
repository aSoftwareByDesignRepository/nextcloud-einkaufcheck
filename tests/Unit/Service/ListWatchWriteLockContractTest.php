<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * SF-02: list/watch mutating writes must serialize on a per-workspace lock
 * so concurrent qty/check/watch edits cannot lost-update or overshoot caps.
 */
final class ListWatchWriteLockContractTest extends TestCase {
	public function testShoppingListMutationsUseWorkspaceLock(): void {
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/ShoppingListService.php'
		);
		foreach (['function add(', 'function update(', 'function delete(', 'function clear('] as $fn) {
			self::assertStringContainsString($fn, $src, $fn);
		}
		self::assertSame(
			4,
			substr_count($src, '->withWorkspaceLock('),
			'add/update/delete/clear must each call withWorkspaceLock',
		);
		self::assertStringContainsString("LOCK_PREFIX = 'ekc-li-'", $src);
	}

	public function testWatchMutationsUseWorkspaceLock(): void {
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/WatchService.php'
		);
		foreach (['function add(', 'function update(', 'function delete('] as $fn) {
			self::assertStringContainsString($fn, $src, $fn);
		}
		self::assertSame(
			3,
			substr_count($src, '->withWorkspaceLock('),
			'add/update/delete must each call withWorkspaceLock',
		);
		self::assertStringContainsString("LOCK_PREFIX = 'ekc-wa-'", $src);
		self::assertLessThanOrEqual(64, strlen('ekc-wa-' . PHP_INT_MAX));
		self::assertLessThanOrEqual(64, strlen('ekc-li-' . PHP_INT_MAX));
	}
}
