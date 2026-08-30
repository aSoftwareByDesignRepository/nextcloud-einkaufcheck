<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Exception;

/**
 * Sliding-window rate limit exceeded. Mapped to HTTP 429.
 */
class RateLimitExceededException extends \RuntimeException {
	public function __construct(string $message = 'Too many requests. Try again later.') {
		parent::__construct($message);
	}

	public function getErrorCode(): string {
		return 'rate_limited';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetails(): array {
		return [];
	}
}
