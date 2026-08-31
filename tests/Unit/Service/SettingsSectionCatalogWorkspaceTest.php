<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

class SettingsSectionCatalogWorkspaceTest extends TestCase {
	public function testMembersAndWorkspaceAreManagerOnly(): void {
		$c = new SettingsSectionCatalog();
		self::assertTrue($c->isManagerOnly('workspace'));
		self::assertTrue($c->isManagerOnly('members'));
		self::assertFalse($c->isManagerOnly('general'));
		$visible = $c->visibleSections(false, false);
		self::assertNotContains('workspace', $visible);
		self::assertNotContains('members', $visible);
		self::assertNotContains('access', $visible);
		$asManager = $c->visibleSections(false, true);
		self::assertContains('workspace', $asManager);
		self::assertContains('members', $asManager);
	}

	public function testRouteRequirementIncludesNewSections(): void {
		$req = SettingsSectionCatalog::routeRequirement();
		self::assertStringContainsString('workspace', $req);
		self::assertStringContainsString('members', $req);
	}
}
