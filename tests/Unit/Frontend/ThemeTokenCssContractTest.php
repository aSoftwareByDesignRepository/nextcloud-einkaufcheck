<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Feature CSS must map to Nextcloud / --ekc-* tokens. Bare hex is only allowed
 * inside var() fallbacks, @media print ink, and the intentional QR canvas token.
 */
final class ThemeTokenCssContractTest extends TestCase {
	public function testAppCssHasPrivateBadgeAndNoBareInkOutsidePrint(): void {
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
		self::assertStringContainsString('.ekc-badge--private', $src);
		self::assertStringContainsString('var(--color-main-text)', $src);
		self::assertStringContainsString('var(--ekc-shadow-md)', $src);
		self::assertStringContainsString('.ekc-app--settings-locked', $src);
		self::assertStringNotContainsString('rgba(0, 0, 0, 0.12)', $src);
		self::assertStringNotContainsString('#a00000', $src);
		$this->assertNoBareHexOutsidePrint($src, 'css/app.css');
	}

	public function testCommonCssFilesHaveNoBareInkOutsidePrint(): void {
		$root = dirname(__DIR__, 3) . '/css/common';
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		/** @var SplFileInfo $file */
		foreach ($it as $file) {
			if (!$file->isFile() || !str_ends_with($file->getFilename(), '.css')) {
				continue;
			}
			$rel = 'css/common/' . $file->getFilename();
			$src = (string)file_get_contents($file->getPathname());
			$this->assertNoBareHexOutsidePrint($src, $rel);
		}
	}

	public function testTokensMapPrimaryToNextcloud(): void {
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/tokens.css');
		self::assertStringContainsString('--ekc-primary: var(--color-primary-element)', $src);
		self::assertStringContainsString('--ekc-accent: var(--color-primary-element)', $src);
		self::assertStringContainsString('--ekc-text: var(--color-main-text)', $src);
		self::assertStringContainsString('--ekc-bg-card: var(--color-main-background)', $src);
		self::assertStringContainsString('body[data-theme-dark-highcontrast]', $src);
		self::assertStringContainsString('prefers-contrast: more', $src);
		self::assertStringContainsString('@media (forced-colors: active)', (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/accessibility.css'));
	}

	private function assertNoBareHexOutsidePrint(string $src, string $label): void {
		$withoutComments = preg_replace('/\/\*[\s\S]*?\*\/|\/\/[^\n]*/', '', $src) ?? $src;
		$withoutPrint = preg_replace('/@media\s+print\s*\{[\s\S]*?\}\s*/', '', $withoutComments) ?? $withoutComments;
		// QR scan canvas is the only intentional non-var hex on :root.
		$withoutQr = preg_replace('/--ekc-qr-canvas:\s*#[0-9a-fA-F]{3,8}\s*;/', '', $withoutPrint) ?? $withoutPrint;
		if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $withoutQr, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[0] as [$hex, $offset]) {
				$before = substr($withoutQr, max(0, $offset - 80), 80);
				self::assertMatchesRegularExpression(
					'/var\s*\([^)]*$/',
					$before,
					"Bare hex {$hex} in {$label} outside var() fallback near offset {$offset}",
				);
			}
		}
	}
}
