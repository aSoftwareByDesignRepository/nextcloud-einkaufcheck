<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Contributors must not hit settingsSave when showing offers/trends, and PLZ
 * chrome must lock for non-managers (matches server SF-01 binding).
 */
final class ContributorPlzChromeContractTest extends TestCase {
	public function testOffersAndTrendsSavePrefsOnlyWhenManager(): void {
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertStringContainsString('if (canManageSettings) {', $src);
		self::assertGreaterThanOrEqual(
			2,
			substr_count($src, 'await api.put(urls.settingsSave'),
			'offers + trends still save for managers',
		);
		self::assertStringContainsString(
			"Only managers can change the postcode. Refresh still updates this space’s saved offers.",
			$src,
		);
		self::assertStringContainsString("el.disabled = true;", $src);
		self::assertStringContainsString("'ekc-plz', 'ekc-week'", $src);
		self::assertStringContainsString('ekc-app--settings-locked', $src);
	}
}
