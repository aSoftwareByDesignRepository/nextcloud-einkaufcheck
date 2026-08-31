<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** Compare-focus layout: hide entire side column (list + staples), persist per workspace, WCAG toolbar. */
final class LayoutModeContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testTemplateExposesLayoutToolbarAndSideControls(): void
	{
		$main = (string)file_get_contents($this->root . '/templates/main.php');
		self::assertStringContainsString('id="ekc-layout-toggle"', $main);
		self::assertStringContainsString('role="toolbar"', $main);
		self::assertStringContainsString('aria-controls="ekc-side"', $main);
		self::assertStringContainsString('id="ekc-side"', $main);
		self::assertStringContainsString('id="ekc-list-card"', $main);
		self::assertStringContainsString('id="ekc-watch-card"', $main);
		self::assertStringContainsString('layout-bootstrap.php', $main);
		self::assertStringContainsString('__ekcLayoutBootstrap', (string)file_get_contents($this->root . '/templates/common/layout-bootstrap.php'));
	}

	public function testJsHidesEntireSidePanelInCompareMode(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString('ekc-side', $js);
		self::assertStringContainsString('side.inert', $js);
		self::assertStringContainsString('ekc:layout:', $js);
		self::assertStringContainsString('requestAnimationFrame(revealList)', $js);
		self::assertStringContainsString('applyLayoutModeEarly', $js);
		self::assertStringContainsString('bindLayoutHideList', $js);
		self::assertStringContainsString('applyPlaceholders', $js);
	}

	public function testCssHidesSideColumnInCompareFocus(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		self::assertStringContainsString('.ekc-side[hidden]', $css);
		self::assertStringContainsString('ekc-app--compare-focus', $css);
		self::assertStringContainsString(':not(.ekc-app--compare-focus) .ekc-side', $css);
		self::assertStringContainsString('ekc-app--compare-focus .ekc-page-grid', $css);
		self::assertStringContainsString('ekc-app--compare-focus .ekc-side', $css);
		self::assertStringNotContainsString('display: contents', $css);
	}
}
