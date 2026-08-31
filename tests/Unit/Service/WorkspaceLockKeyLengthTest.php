<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

/**
 * oc_file_locks.key is VARCHAR(64). Oversized keys silently truncate and
 * break ensurePersonal / createWorkspace serialization.
 */
final class WorkspaceLockKeyLengthTest extends TestCase {
	public function testPersonalAndCreateLockKeysFitFileLocksColumn(): void {
		$uid = str_repeat('u', 200);
		$personal = WorkspaceService::personalLockKey($uid);
		$create = WorkspaceService::createLockKey($uid);
		self::assertLessThanOrEqual(64, strlen($personal), $personal);
		self::assertLessThanOrEqual(64, strlen($create), $create);
		self::assertStringStartsWith('ekc-pw-', $personal);
		self::assertStringStartsWith('ekc-wc-', $create);
		self::assertNotSame($personal, $create);
	}
}
