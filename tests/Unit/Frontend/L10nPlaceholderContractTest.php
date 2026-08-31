<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** NC translate() leaves %s literal — l10n.js must patch window.t. */
final class L10nPlaceholderContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testL10nScriptLoadsBeforeAppAndPatchesPlaceholders(): void
	{
		$page = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$l10n = (string)file_get_contents($this->root . '/js/common/l10n.js');
		self::assertStringContainsString("Util::addScript(Application::APP_ID, 'common/l10n');", $page);
		self::assertStringContainsString('applyPlaceholders', $l10n);
		self::assertStringContainsString('window.t = translate', $l10n);
		self::assertStringContainsString('%s', $l10n);
	}

	public function testOffersCountUsesTranslatablePlaceholder(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$de = json_decode((string)file_get_contents($this->root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$catalog = $de['translations'] ?? [];
		self::assertArrayHasKey('%s offers shown.', $catalog);
		self::assertStringContainsString('%s Angebote', (string)$catalog['%s offers shown.']);
		self::assertStringContainsString('ekcTranslate(\'%s offers shown.\'', $js);
	}
}
