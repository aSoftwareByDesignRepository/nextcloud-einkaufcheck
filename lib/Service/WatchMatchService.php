<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

/**
 * Match Vorrats-Queries against weekly offers.
 *
 * Invariant: a watch notifies only on real staple matches. Infix substrings
 * ("eis" in "Reis") and Jaccard "half the tokens" matches are forbidden.
 */
class WatchMatchService {
	public const MAX_HITS_PER_WATCH = 25;
	public const MAX_HITS_TOTAL = 100;

	private const NOISE = [
		'xxl' => true, 'xl' => true, 'bio' => true, 'frisch' => true, 'frische' => true,
		'haltbare' => true, 'premium' => true, 'aktion' => true, 'plus' => true, 'je' => true,
		'packung' => true, 'beutel' => true, 'becher' => true, 'dose' => true, 'glas' => true,
		'stuck' => true, 'und' => true, 'mit' => true, 'aus' => true, 'der' => true, 'die' => true,
		'das' => true, 'zum' => true, 'zur' => true, 'im' => true, 'in' => true, 'von' => true,
		'fur' => true, 'fuer' => true, 'neu' => true,
	];

	/**
	 * @param array<string, mixed> $watch
	 * @param array<string, mixed> $offer
	 */
	public function matches(array $watch, array $offer): bool {
		$store = trim((string)($watch['store'] ?? ''));
		if ($store !== '' && !in_array($store, ['all', '*'], true) && $store !== ($offer['store'] ?? '')) {
			return false;
		}
		if (isset($watch['max_price']) && $watch['max_price'] !== null && $watch['max_price'] !== '') {
			if (!isset($offer['price']) || $offer['price'] === null) {
				return false;
			}
			if ((float)$offer['price'] > (float)$watch['max_price'] + 1e-9) {
				return false;
			}
		}
		if (isset($watch['max_per_kg']) && $watch['max_per_kg'] !== null && $watch['max_per_kg'] !== '') {
			if (!isset($offer['per_kg']) || $offer['per_kg'] === null) {
				return false;
			}
			if ((float)$offer['per_kg'] > (float)$watch['max_per_kg'] + 1e-9) {
				return false;
			}
		}

		$query = trim((string)($watch['query'] ?? ''));
		if ($query === '') {
			return false;
		}

		$qn = $this->normalize($query);
		$hay = $this->normalize(trim(($offer['brand'] ?? '') . ' ' . ($offer['name'] ?? '')));
		if ($qn === '' || $hay === '') {
			return false;
		}

		// Whole-query noise ("bio", "plus", "frisch") must never match alone —
		// those tokens appear on nearly every Bio/Aktion shelf label.
		if (isset(self::NOISE[$qn])) {
			return false;
		}

		// Whole-query, word-bounded. Infix is forbidden: "eis" must not hit "Reis".
		if (mb_strlen($qn) >= 4 && $this->isWordBounded($hay, $qn)) {
			return true;
		}

		$qt = $this->tokens($query, (string)($watch['brand'] ?? ''));
		$ot = $this->tokens((string)($offer['name'] ?? ''), (string)($offer['brand'] ?? ''));
		if ($qt !== [] && $ot !== []) {
			$oSet = array_fill_keys($ot, true);
			$subset = true;
			foreach ($qt as $t) {
				if (!isset($oSet[$t])) {
					$subset = false;
					break;
				}
			}
			if ($subset) {
				return true;
			}
		}

		// 3-character watches: exact token only (no Jaccard, no infix).
		if (mb_strlen($qn) === 3) {
			$hayTokens = preg_split('/[^a-z0-9]+/', $hay) ?: [];
			foreach ($hayTokens as $ht) {
				if ($ht === $qn) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param list<array<string, mixed>> $watches
	 * @param list<array<string, mixed>> $offers
	 * @return list<array<string, mixed>>
	 */
	public function hits(array $watches, array $offers): array {
		$hits = [];
		foreach ($watches as $watch) {
			if (isset($watch['enabled']) && !$watch['enabled']) {
				continue;
			}
			$perWatch = 0;
			foreach ($offers as $offer) {
				if (count($hits) >= self::MAX_HITS_TOTAL) {
					return $hits;
				}
				if ($perWatch >= self::MAX_HITS_PER_WATCH) {
					break;
				}
				if (!$this->matches($watch, $offer)) {
					continue;
				}
				$hits[] = [
					'watch_id' => $watch['id'] ?? null,
					'query' => $watch['query'] ?? '',
					'max_price' => $watch['max_price'] ?? null,
					'max_per_kg' => $watch['max_per_kg'] ?? null,
					'offer' => [
						'store' => $offer['store'] ?? '',
						'brand' => $offer['brand'] ?? '',
						'name' => $offer['name'] ?? '',
						'pack' => $offer['pack'] ?? '',
						'price' => $offer['price'] ?? null,
						'per_kg' => $offer['per_kg'] ?? null,
						'per_l' => $offer['per_l'] ?? null,
						'url' => $offer['url'] ?? '',
					],
				];
				$perWatch++;
			}
		}
		return $hits;
	}

	public function normalize(string $value): string {
		$text = $value;
		if (class_exists(\Normalizer::class)) {
			$normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
			if (is_string($normalized) && $normalized !== '') {
				$text = $normalized;
			}
		}
		$text = str_replace(['®', '™', '©'], '', $text);
		$text = preg_replace("/[’`´]/u", "'", $text) ?? $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		$text = mb_strtolower(trim($text), 'UTF-8');
		return str_replace(['ä', 'ö', 'ü', 'ß'], ['a', 'o', 'u', 'ss'], $text);
	}

	/**
	 * @return list<string>
	 */
	private function tokens(string $name, string $brand = ''): array {
		$nameN = $this->normalize($name);
		$brandN = $this->normalize($brand);
		if ($brandN !== '' && (str_starts_with($nameN, $brandN . ' ') || $nameN === $brandN)) {
			$nameN = trim(mb_substr($nameN, mb_strlen($brandN)));
		}
		$nameN = preg_replace('/\d+(?:[.,]\d+)?\s*(?:kg|g|l|ml|liter|stk|st|er|x)\b/iu', ' ', $nameN) ?? $nameN;
		$parts = preg_split('/[^a-z0-9]+/', $nameN) ?: [];
		$out = [];
		foreach ($parts as $t) {
			if ($t === '' || isset(self::NOISE[$t]) || ctype_digit($t) || strlen($t) < 3) {
				continue;
			}
			$stem = $this->stem($t);
			if (strlen($stem) >= 3) {
				$out[$stem] = true;
			}
		}
		$keys = array_keys($out);
		sort($keys);
		return $keys;
	}

	private function isWordBounded(string $hay, string $needle): bool {
		if ($needle === '') {
			return false;
		}
		return (bool)preg_match(
			'/(?:^|[^a-z0-9])' . preg_quote($needle, '/') . '(?:[^a-z0-9]|$)/u',
			$hay,
		);
	}

	private function stem(string $token): string {
		foreach (['chen', 'lein', 'heiten', 'ungen', 'ern', 'en', 'er', 'es', 'e', 'n', 's'] as $suf) {
			$len = strlen($suf);
			if (str_ends_with($token, $suf) && strlen($token) - $len >= 4) {
				return substr($token, 0, -$len);
			}
		}
		return $token;
	}
}
