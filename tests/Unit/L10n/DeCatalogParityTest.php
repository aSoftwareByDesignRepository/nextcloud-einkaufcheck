<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every user-facing t() / $l->t() literal must exist in de.json.
 */
final class DeCatalogParityTest extends TestCase {
	/** @var array<string, true> */
	private const IDENTITY_ALLOW = [
		'EinkaufCheck' => true,
		'ALDI Nord' => true,
		'ALDI Nord only' => true,
		'Lidl' => true,
		'Lidl only' => true,
		'ALDI' => true,
		'WhatsApp' => true,
		'CSV' => true,
		'Non-food' => true,
		'€/kg' => true,
		'Max €/kg' => true,
		'Max price (€)' => true,
	];

	public function testPhpAndJsLiteralsExistInDeAndAreTranslated(): void {
		$root = dirname(__DIR__, 3);
		$de = json_decode((string)file_get_contents($root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$catalog = $de['translations'] ?? [];
		self::assertIsArray($catalog);

		$keys = array_merge($this->extractPhp($root), $this->extractJs($root));
		self::assertNotEmpty($keys);

		$missing = [];
		$identity = [];
		foreach ($keys as $key) {
			if ($key === '') {
				continue;
			}
			if (!array_key_exists($key, $catalog)) {
				$missing[] = $key;
				continue;
			}
			if (isset(self::IDENTITY_ALLOW[$key])) {
				continue;
			}
			if ($catalog[$key] === $key && str_contains($key, ' ') && preg_match('/[A-Za-z]{4,}/', $key)) {
				$identity[] = $key;
			}
		}

		self::assertSame([], $missing, 'Missing de.json keys: ' . implode(' | ', $missing));
		self::assertSame([], $identity, 'Untranslated German multi-word strings: ' . implode(' | ', $identity));
	}

	/** @return list<string> */
	private function extractPhp(string $root): array {
		$found = [];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		/** @var SplFileInfo $file */
		foreach ($it as $file) {
			if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
				continue;
			}
			$path = $file->getPathname();
			if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
				continue;
			}
			$src = (string)file_get_contents($path);
			if (preg_match_all("/->t\\(\\s*'((?:\\\\'|[^'])*)'/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
		}
		return array_values(array_unique($found));
	}

	/** @return list<string> */
	private function extractJs(string $root): array {
		$found = [];
		$files = array_merge(
			glob($root . '/js/*.js') ?: [],
			glob($root . '/js/common/*.js') ?: [],
		);
		foreach ($files as $path) {
			$src = (string)file_get_contents($path);
			if (preg_match_all("/t\\(APP,\\s*'((?:\\\\'|[^'])*)'/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
		}
		return array_values(array_unique($found));
	}
}
