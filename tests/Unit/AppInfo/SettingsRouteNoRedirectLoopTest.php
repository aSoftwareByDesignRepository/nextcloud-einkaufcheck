<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\AppInfo;

use OCA\EinkaufCheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * `/settings/{section}` must not declare a default section. Symfony then omits
 * `general` from generated URLs, so settingsIndex 302s /settings → /settings
 * forever (Firefox: “The page isn’t redirecting properly”).
 */
class SettingsRouteNoRedirectLoopTest extends TestCase {
	public function testSectionRouteHasNoDefaultSoIndexCanRedirectOnce(): void {
		$routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		self::assertIsArray($routes['routes'] ?? null);
		$index = null;
		$section = null;
		foreach ($routes['routes'] as $route) {
			if (($route['name'] ?? '') === 'page#settingsIndex') {
				$index = $route;
			}
			if (($route['name'] ?? '') === 'page#settings') {
				$section = $route;
			}
		}
		self::assertNotNull($index);
		self::assertSame('/settings', $index['url']);
		self::assertNotNull($section);
		self::assertSame('/settings/{section}', $section['url']);
		self::assertArrayNotHasKey('defaults', $section);
		self::assertSame(SettingsSectionCatalog::routeRequirement(), $section['requirements']['section'] ?? '');

		$controller = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertStringContainsString('return $this->settings(SettingsSectionCatalog::DEFAULT_SECTION);', $controller);
		self::assertDoesNotMatchRegularExpression(
			'/function settingsIndex\(\):\s*RedirectResponse/',
			$controller,
		);
	}
}
