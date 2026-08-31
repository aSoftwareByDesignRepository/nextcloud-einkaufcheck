<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\OfferFetchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Prefs live on einkaufcheck_ws via WorkspaceService — OfferFetch must not
 * keep a parallel oc_preferences writer (dual source of truth).
 */
final class OfferFetchNoUserPrefsSoTTest extends TestCase {
	public function testLegacyUserPrefsMethodsRemoved(): void {
		$ref = new ReflectionClass(OfferFetchService::class);
		self::assertFalse($ref->hasMethod('getUserPrefs'));
		self::assertFalse($ref->hasMethod('saveUserPrefs'));
	}
}
