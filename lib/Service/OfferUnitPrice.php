<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

/**
 * Prefer store-provided €/kg or €/l; otherwise derive from pack + price
 * (mass, volume, or piece counts such as eggs).
 */
final class OfferUnitPrice {
	public const KIND_KG = 'kg';
	public const KIND_L = 'l';
	public const KIND_PC = 'pc';

	/**
	 * @return array{unit_price: ?float, unit_kind: ?string, unit_label: string}
	 */
	public static function resolve(
		?float $price,
		?float $perKg,
		?float $perL,
		string $pack,
		string $existingLabel = '',
	): array {
		if ($perKg !== null && self::isUsableAmount($perKg)) {
			return [
				'unit_price' => $perKg,
				'unit_kind' => self::KIND_KG,
				'unit_label' => self::label($perKg, self::KIND_KG),
			];
		}
		if ($perL !== null && self::isUsableAmount($perL)) {
			return [
				'unit_price' => $perL,
				'unit_kind' => self::KIND_L,
				'unit_label' => self::label($perL, self::KIND_L),
			];
		}

		$fromPack = self::fromPack($price, $pack);
		if ($fromPack !== null) {
			return $fromPack;
		}

		$existingLabel = trim($existingLabel);
		return [
			'unit_price' => null,
			'unit_kind' => null,
			'unit_label' => mb_substr($existingLabel, 0, 64),
		];
	}

	/**
	 * @param array<string, mixed> $offer
	 * @return array<string, mixed>
	 */
	public static function enrich(array $offer): array {
		$resolved = self::resolve(
			isset($offer['price']) && is_numeric($offer['price']) ? (float)$offer['price'] : null,
			isset($offer['per_kg']) && is_numeric($offer['per_kg']) ? (float)$offer['per_kg'] : null,
			isset($offer['per_l']) && is_numeric($offer['per_l']) ? (float)$offer['per_l'] : null,
			(string)($offer['pack'] ?? ''),
			(string)($offer['unit_label'] ?? ''),
		);
		$offer['unit_price'] = $resolved['unit_price'];
		$offer['unit_kind'] = $resolved['unit_kind'];
		if ($resolved['unit_label'] !== '') {
			$offer['unit_label'] = $resolved['unit_label'];
		}
		return $offer;
	}

	/**
	 * @return array{unit_price: float, unit_kind: string, unit_label: string}|null
	 */
	private static function fromPack(?float $price, string $pack): ?array {
		if ($price === null || $pack === '' || !self::isUsableAmount($price)) {
			return null;
		}
		$normalized = str_replace(',', '.', $pack);

		if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g|l|ml|liter)\b/iu', $normalized, $m) === 1) {
			$amount = (float)$m[1];
			$unit = mb_strtolower($m[2]);
			if ($amount <= 0) {
				return null;
			}
			if ($unit === 'g') {
				if ($amount < 1.0) {
					return null;
				}
				$perKg = round($price / ($amount / 1000.0), 2);
				return self::usableDerived($perKg, self::KIND_KG);
			}
			if ($unit === 'kg') {
				$perKg = round($price / $amount, 2);
				return self::usableDerived($perKg, self::KIND_KG);
			}
			if ($unit === 'ml') {
				if ($amount < 1.0) {
					return null;
				}
				$perL = round($price / ($amount / 1000.0), 2);
				return self::usableDerived($perL, self::KIND_L);
			}
			if ($unit === 'l' || $unit === 'liter') {
				$perL = round($price / $amount, 2);
				return self::usableDerived($perL, self::KIND_L);
			}
		}

		$pieces = self::pieceCount($pack);
		if ($pieces !== null) {
			$perPc = round($price / $pieces, 2);
			return self::usableDerived($perPc, self::KIND_PC);
		}

		return null;
	}

	/**
	 * @return array{unit_price: float, unit_kind: string, unit_label: string}|null
	 */
	private static function usableDerived(float $value, string $kind): ?array {
		if (!self::isUsableAmount($value)) {
			return null;
		}
		return [
			'unit_price' => $value,
			'unit_kind' => $kind,
			'unit_label' => self::label($value, $kind),
		];
	}

	private static function pieceCount(string $pack): ?int {
		$p = mb_strtolower($pack);
		if (preg_match('/\b(\d{1,3})\s*(?:stück|stueck|stk\.?|st\.?)\b/u', $p, $m) === 1) {
			$n = (int)$m[1];
			return ($n >= 2 && $n <= 100) ? $n : null;
		}
		if (preg_match('/\b(\d{1,3})\s*x\b/u', $p, $m) === 1 || preg_match('/\bx\s*(\d{1,3})\b/u', $p, $m) === 1) {
			$n = (int)$m[1];
			return ($n >= 2 && $n <= 100) ? $n : null;
		}
		if (preg_match('/\b(\d{1,2})\s*er\b/u', $p, $m) === 1) {
			$n = (int)$m[1];
			return ($n >= 2 && $n <= 48) ? $n : null;
		}
		return null;
	}

	/**
	 * Prices and unit rates must be finite and non-negative. Zero is allowed
	 * (freebies); negatives would invert week-tip “cheaper” verdicts.
	 */
	public static function isUsableAmount(float $value): bool {
		return is_finite($value) && $value >= 0.0 && $value <= 99999.99;
	}

	private static function label(float $value, string $kind): string {
		$formatted = number_format($value, 2, ',', '.') . ' €';
		return match ($kind) {
			self::KIND_KG => $formatted . '/kg',
			self::KIND_L => $formatted . '/l',
			self::KIND_PC => $formatted . '/St.',
			default => $formatted,
		};
	}
}
