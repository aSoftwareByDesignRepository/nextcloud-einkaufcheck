<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\SettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class SettingsSectionCatalogTest extends TestCase {
	private SettingsSectionCatalog $catalog;

	protected function setUp(): void {
		$this->catalog = new SettingsSectionCatalog();
	}

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);
		return $l;
	}

	public function testRouteRequirementMatchesSections(): void {
		self::assertSame(implode('|', SettingsSectionCatalog::SECTIONS), SettingsSectionCatalog::routeRequirement());
		self::assertContains(SettingsSectionCatalog::DEFAULT_SECTION, SettingsSectionCatalog::SECTIONS);
	}

	public function testAccessIsAdminOnly(): void {
		self::assertTrue($this->catalog->isAdminOnly('access'));
		self::assertFalse($this->catalog->isAdminOnly('general'));
		self::assertSame(['general', 'stores'], $this->catalog->visibleSections(false));
		self::assertSame(SettingsSectionCatalog::SECTIONS, $this->catalog->visibleSections(true));
	}

	public function testLabelsGoThroughL10n(): void {
		$l = $this->l10n();
		foreach (SettingsSectionCatalog::SECTIONS as $slug) {
			self::assertStringStartsWith('T:', $this->catalog->label($l, $slug));
			self::assertStringStartsWith('T:', $this->catalog->navLabel($l, $slug));
			self::assertStringStartsWith('T:', $this->catalog->help($l, $slug));
			self::assertStringStartsWith('parts/settings/', $this->catalog->templatePart($slug));
		}
	}

	public function testRejectsUnknownSection(): void {
		self::assertFalse($this->catalog->isSection(''));
		self::assertFalse($this->catalog->isSection('Access'));
		self::assertFalse($this->catalog->isSection('../general'));
		self::assertSame('parts/settings/general', $this->catalog->templatePart('nope'));
	}
}
