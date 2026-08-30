<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

/**
 * Opt-in product pictures from retailer CDNs.
 *
 * Fail closed: unknown host, IP literal, dangerous path, userinfo, or
 * non-https → empty string. Scene7 is a shared Adobe CDN, so ALDI images
 * must also sit under /is/image/aldinord.
 *
 * Host matching is exact suffix with a dot prefix so xlidl.de cannot
 * piggyback on lidl.de.
 */
final class OfferImagePolicy {
	/** @var list<string> */
	private const HOST_SUFFIXES = [
		'scene7.com',
		'lidl.de',
		'lidlplus.com',
		'aldi-nord.de',
	];

	public static function sanitize(mixed $url): string {
		$u = trim((string)$url);
		if ($u === '' || strlen($u) > 2000) {
			return '';
		}
		if (preg_match('/[\x00-\x1f\x7f]/', $u) === 1) {
			return '';
		}
		if (str_contains($u, '\\') || str_contains($u, '@')) {
			return '';
		}
		$parts = parse_url($u);
		if (!is_array($parts)) {
			return '';
		}
		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return '';
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return '';
		}
		if (isset($parts['port']) && (int)$parts['port'] !== 443) {
			return '';
		}
		$host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
		if ($host === '' || str_contains($host, '%') || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
			return '';
		}
		if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $host) === 1) {
			return '';
		}
		if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
			return '';
		}
		if (!self::hostAllowed($host)) {
			return '';
		}
		$path = (string)($parts['path'] ?? '/');
		if ($path === '') {
			$path = '/';
		}
		if (preg_match('/\.(svgz?|html?|js|mjs|xml|php|xhtml|shtml)(?:$|[?#])/i', $path) === 1) {
			return '';
		}
		$decoded = strtolower((string)rawurldecode($path));
		if (self::isScene7($host) && !str_starts_with($decoded, '/is/image/aldinord')) {
			return '';
		}
		if (preg_match('~^https://[a-z0-9.-]+(?::443)?(?:[/?#].*)?$~i', $u) !== 1) {
			return '';
		}
		$query = '';
		if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
			if (preg_match('/[\x00-\x1f\x7f\\\\]/', $parts['query']) === 1) {
				return '';
			}
			$query = '?' . $parts['query'];
		}
		return 'https://' . $host . $path . $query;
	}

	public static function hostAllowed(string $host): bool {
		$host = strtolower(rtrim($host, '.'));
		foreach (self::HOST_SUFFIXES as $suffix) {
			if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CSP img-src hosts. Must stay in lockstep with HOST_SUFFIXES.
	 *
	 * @return list<string>
	 */
	public static function cspImageDomains(): array {
		return [
			'https://*.scene7.com',
			'https://*.lidl.de',
			'https://www.lidl.de',
			'https://lidl.de',
			'https://*.lidlplus.com',
			'https://lidlplus.com',
			'https://*.aldi-nord.de',
			'https://aldi-nord.de',
		];
	}

	private static function isScene7(string $host): bool {
		return $host === 'scene7.com' || str_ends_with($host, '.scene7.com');
	}
}
