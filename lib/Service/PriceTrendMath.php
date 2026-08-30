<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

/**
 * Classify this week's price against earlier weeks. Conservative on purpose:
 * a 1-cent dip is not "cheap", and a single snapshot is not a trend.
 */
final class PriceTrendMath {
	public const MIN_OTHER_WEEKS = 1;
	public const CHEAP_DROP_PCT = 8.0;
	public const CHEAP_ABS = 0.05;
	public const LOWEST_ABS = 0.10;
	public const DEAR_DROP_PCT = -8.0;
	public const DEAR_ABS = 0.05;

	/**
	 * @param list<float> $otherWeeks Prices from weeks other than the current snapshot.
	 * @return array{
	 *   verdict: 'cheap'|'dear'|'usual'|'new'|'unknown',
	 *   drop_pct: ?float,
	 *   avg: ?float,
	 *   min: ?float,
	 *   max: ?float,
	 *   is_lowest: bool,
	 *   is_highest: bool,
	 *   weeks: int
	 * }
	 */
	public static function classify(?float $current, array $otherWeeks): array {
		$unknown = [
			'verdict' => 'unknown',
			'drop_pct' => null,
			'avg' => null,
			'min' => null,
			'max' => null,
			'is_lowest' => false,
			'is_highest' => false,
			'weeks' => count($otherWeeks) + ($current !== null ? 1 : 0),
		];
		if ($current === null || !is_finite($current) || $current < 0) {
			return $unknown;
		}

		$currentCents = (int)round($current * 100);
		$others = [];
		foreach ($otherWeeks as $p) {
			if (is_float($p) || is_int($p)) {
				$n = (float)$p;
				if (is_finite($n) && $n >= 0) {
					$others[] = (int)round($n * 100);
				}
			}
		}

		$weeks = count($others) + 1;
		if ($others === []) {
			$cur = $currentCents / 100.0;
			return [
				'verdict' => 'new',
				'drop_pct' => null,
				'avg' => $cur,
				'min' => $cur,
				'max' => $cur,
				'is_lowest' => true,
				'is_highest' => true,
				'weeks' => $weeks,
			];
		}

		$avgCents = (int)round(array_sum($others) / count($others));
		$minAll = min($currentCents, ...$others);
		$maxAll = max($currentCents, ...$others);
		$dropPct = $avgCents > 0 ? (($avgCents - $currentCents) / $avgCents) * 100.0 : 0.0;
		$isLowest = $currentCents <= $minAll;
		$isHighest = $currentCents >= $maxAll;
		$deltaCents = $avgCents - $currentCents;

		$cheapFloor = (int)round(self::CHEAP_ABS * 100);
		$lowestFloor = (int)round(self::LOWEST_ABS * 100);
		$dearFloor = (int)round(self::DEAR_ABS * 100);
		$cheap = ($dropPct >= self::CHEAP_DROP_PCT && $deltaCents >= $cheapFloor)
			|| ($isLowest && $deltaCents >= $lowestFloor);
		$dear = ($dropPct <= self::DEAR_DROP_PCT && (-$deltaCents) >= $dearFloor);

		$verdict = 'usual';
		if ($cheap && !$dear) {
			$verdict = 'cheap';
		} elseif ($dear && !$cheap) {
			$verdict = 'dear';
		}

		return [
			'verdict' => $verdict,
			'drop_pct' => round($dropPct, 1),
			'avg' => round($avgCents / 100.0, 2),
			'min' => round($minAll / 100.0, 2),
			'max' => round($maxAll / 100.0, 2),
			'is_lowest' => $isLowest,
			'is_highest' => $isHighest && $currentCents > $minAll,
			'weeks' => $weeks,
		];
	}

	/**
	 * Prefer €/kg when every point (including current) has it; otherwise pack €.
	 *
	 * @param list<array{price: ?float, per_kg: ?float}> $points
	 * @return array{unit: 'kg'|'pack', values: list<float>}
	 */
	public static function seriesMetric(array $points): array {
		if ($points === []) {
			return ['unit' => 'pack', 'values' => []];
		}
		$kg = [];
		foreach ($points as $p) {
			if (!isset($p['per_kg']) || $p['per_kg'] === null || !is_finite((float)$p['per_kg'])) {
				$kg = [];
				break;
			}
			$kg[] = (float)$p['per_kg'];
		}
		if ($kg !== [] && count($kg) === count($points)) {
			return ['unit' => 'kg', 'values' => $kg];
		}
		$pack = [];
		foreach ($points as $p) {
			if (!isset($p['price']) || $p['price'] === null || !is_finite((float)$p['price'])) {
				continue;
			}
			$pack[] = (float)$p['price'];
		}
		return ['unit' => 'pack', 'values' => $pack];
	}
}
