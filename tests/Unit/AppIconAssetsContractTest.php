<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit;

use OCA\EinkaufCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

/**
 * NC header / app-menu icons must match Check-family stroke glyphs and the in-app cart.
 */
final class AppIconAssetsContractTest extends TestCase
{
	private string $imgDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->imgDir = dirname(__DIR__, 2) . '/img';
	}

	public function testRequiredIconFilesExist(): void
	{
		foreach (['app.svg', 'app-dark.svg', 'app-menu.svg'] as $file) {
			$path = $this->imgDir . '/' . $file;
			$this->assertFileExists($path, $file);
			$this->assertGreaterThan(200, (int)filesize($path), $file . ' must not be a stub');
		}
	}

	public function testHeaderAndMenuIconsAreWhiteShoppingCartLikeSiblings(): void
	{
		foreach (['app.svg', 'app-menu.svg'] as $file) {
			$svg = (string)file_get_contents($this->imgDir . '/' . $file);
			$this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
			$this->assertStringContainsString('stroke="#ffffff"', $svg);
			$this->assertStringContainsString('stroke-width="1.75"', $svg);
			$this->assertStringContainsString('aria-hidden="true"', $svg);
			$this->assertStringNotContainsString('currentColor', $svg);
			$this->assertStringContainsString('cx="8"', $svg, 'Cart front wheel');
			$this->assertStringContainsString('cx="19"', $svg, 'Cart rear wheel');
			$this->assertStringContainsString('M2.05 2.05h2l2.66 12.42', $svg);
		}

		$inline = IconCatalog::render('shopping-cart');
		$this->assertStringContainsString('M2.05 2.05h2l2.66 12.42', $inline);
	}

	public function testDarkIconIsBlackShoppingCart(): void
	{
		$svg = (string)file_get_contents($this->imgDir . '/app-dark.svg');
		$this->assertStringContainsString('stroke="#000000"', $svg);
		$this->assertStringNotContainsString('stroke="#ffffff"', $svg);
		$this->assertStringContainsString('cx="8"', $svg);
		$this->assertStringContainsString('cx="19"', $svg);
	}
}
