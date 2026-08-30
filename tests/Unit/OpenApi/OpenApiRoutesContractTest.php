<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\OpenApi;

use PHPUnit\Framework\TestCase;

/**
 * openapi.json must stay in sync with appinfo/routes.php for /api/*.
 */
final class OpenApiRoutesContractTest extends TestCase {
	public function testOpenApiPathsAndMethodsMatchRoutes(): void {
		$root = dirname(__DIR__, 3);
		$routesFile = $root . '/appinfo/routes.php';
		$openapiFile = $root . '/openapi.json';

		self::assertFileExists($routesFile);
		self::assertFileExists($openapiFile);

		$routesSpec = require $routesFile;
		self::assertIsArray($routesSpec['routes'] ?? null);

		$openapi = json_decode((string)file_get_contents($openapiFile), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($openapi['paths'] ?? null);

		$routeSet = [];
		foreach ($routesSpec['routes'] as $route) {
			if (!is_array($route)) {
				continue;
			}
			$url = (string)($route['url'] ?? '');
			$verb = strtoupper((string)($route['verb'] ?? ''));
			if ($url === '' || $verb === '' || !str_starts_with($url, '/api/')) {
				continue;
			}
			$routeSet[$verb . ' ' . $url] = true;
		}

		$openapiSet = [];
		foreach ($openapi['paths'] as $path => $ops) {
			if (!is_string($path) || !is_array($ops) || !str_starts_with($path, '/api/')) {
				continue;
			}
			foreach ($ops as $methodLower => $_opSpec) {
				if (!is_string($methodLower)) {
					continue;
				}
				$method = strtoupper($methodLower);
				if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
					continue;
				}
				$openapiSet[$method . ' ' . $path] = true;
			}
		}

		$routeKeys = array_keys($routeSet);
		$openapiKeys = array_keys($openapiSet);
		sort($routeKeys);
		sort($openapiKeys);
		self::assertSame(
			$routeKeys,
			$openapiKeys,
			'openapi.json paths/methods must exactly match appinfo/routes.php for /api/*',
		);
	}

	public function testGetOffersDocumentedAsCacheOnly(): void {
		$spec = (string)file_get_contents(dirname(__DIR__, 3) . '/openapi.json');
		self::assertStringContainsString('no live fetch, no preference write', $spec);
		self::assertStringContainsString('offers_stale', $spec);
		self::assertStringContainsString('/api/trends', $spec);
		self::assertStringContainsString('never returns offers_stale', $spec);
		self::assertStringContainsString('show_images', $spec);
	}
}
