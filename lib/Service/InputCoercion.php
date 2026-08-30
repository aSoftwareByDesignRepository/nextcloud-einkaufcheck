<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\Exception\ValidationException;

/**
 * Parse client booleans without PHP's empty() / (bool) traps.
 * (bool)"false" and !empty("false") are both true in PHP.
 */
final class InputCoercion {
	public static function asBool(mixed $value, string $field): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			if ($value === 1 || $value === 1.0) {
				return true;
			}
			if ($value === 0 || $value === 0.0) {
				return false;
			}
			throw self::invalid($field);
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
				return true;
			}
			if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
				return false;
			}
		}
		throw self::invalid($field);
	}

	private static function invalid(string $field): ValidationException {
		return new ValidationException(
			$field . ' must be true or false.',
			[$field => 'Must be a boolean'],
			'invalid_bool',
		);
	}
}
