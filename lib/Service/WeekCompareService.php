<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

/**
 * Annotate the displayed week’s offers with a tip when the same SKU is
 * cheaper in the other cached week (postpone vs buy now).
 *
 * Cache-only: never fetches. Kind mismatch (kg vs pack) → no tip.
 */
class WeekCompareService {
	private const ABS_MIN = 0.05;
	private const REL_MIN = 0.03;

	public function __construct(
		private readonly WatchMatchService $match,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $primary
	 * @param list<array<string, mixed>> $other
	 * @return list<array<string, mixed>>
	 */
	public function annotate(array $primary, array $other, string $otherWeek, string $plz): array {
		$index = [];
		foreach ($other as $offer) {
			if (!is_array($offer)) {
				continue;
			}
			$key = $this->identityKey($offer, $plz);
			$metric = $this->metric($offer);
			if ($key === '' || $metric === null) {
				continue;
			}
			// Keep the cheapest other-week row for this identity.
			if (!isset($index[$key]) || $metric['value'] < $index[$key]['metric']['value']) {
				$index[$key] = ['offer' => $offer, 'metric' => $metric];
			}
		}

		$out = [];
		foreach ($primary as $offer) {
			if (!is_array($offer)) {
				continue;
			}
			$offer['week_tip'] = null;
			$key = $this->identityKey($offer, $plz);
			$mine = $this->metric($offer);
			if ($key !== '' && $mine !== null && isset($index[$key])) {
				$theirs = $index[$key]['metric'];
				if ($mine['kind'] === $theirs['kind']) {
					$offer['week_tip'] = $this->tip($mine['value'], $theirs['value'], $otherWeek, $index[$key]['offer']);
				}
			}
			$out[] = $offer;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $otherOffer
	 * @return array{verdict: string, other_week: string, other_price: ?float, other_unit_price: ?float, other_unit_kind: ?string, saving: float}|null
	 */
	private function tip(float $mine, float $theirs, string $otherWeek, array $otherOffer): ?array {
		$delta = $mine - $theirs;
		$rel = $mine > 0 ? abs($delta) / $mine : abs($delta);
		if (abs($delta) < self::ABS_MIN && $rel < self::REL_MIN) {
			return null;
		}
		$verdict = $delta > 0 ? 'cheaper_later' : 'cheaper_now';
		$otherUnit = OfferUnitPrice::enrich($otherOffer);
		return [
			'verdict' => $verdict,
			'other_week' => $otherWeek === 'next' ? 'next' : 'current',
			'other_price' => isset($otherOffer['price']) && is_numeric($otherOffer['price'])
				? round((float)$otherOffer['price'], 2)
				: null,
			'other_unit_price' => $otherUnit['unit_price'],
			'other_unit_kind' => $otherUnit['unit_kind'],
			'saving' => round(abs($delta), 2),
		];
	}

	/**
	 * @param array<string, mixed> $offer
	 * @return array{value: float, kind: string}|null
	 */
	private function metric(array $offer): ?array {
		$enriched = OfferUnitPrice::enrich($offer);
		if ($enriched['unit_price'] !== null && $enriched['unit_kind'] !== null) {
			return ['value' => (float)$enriched['unit_price'], 'kind' => (string)$enriched['unit_kind']];
		}
		if (isset($offer['price']) && is_numeric($offer['price'])) {
			$price = (float)$offer['price'];
			if (OfferUnitPrice::isUsableAmount($price)) {
				return ['value' => round($price, 2), 'kind' => 'pack'];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $offer
	 */
	public function identityKey(array $offer, string $plz): string {
		$store = trim((string)($offer['store'] ?? ''));
		$name = trim((string)($offer['name'] ?? ''));
		if ($name === '' || ($store !== 'ALDI Nord' && $store !== 'Lidl')) {
			return '';
		}
		$scope = $store === 'ALDI Nord' ? PriceHistoryService::NATIONWIDE : $plz;
		return sha1(implode("\0", [
			$this->match->normalize($store),
			$this->match->normalize((string)($offer['brand'] ?? '')),
			$this->match->normalize($name),
			$this->match->normalize((string)($offer['pack'] ?? '')),
			$scope,
		]));
	}
}
