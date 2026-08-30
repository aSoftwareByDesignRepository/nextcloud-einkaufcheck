<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Exception;

/**
 * Client-correctable input. Mapped to HTTP 400, or 409 for busy/full/stale/lockout codes.
 */
class ValidationException extends \InvalidArgumentException {
	/**
	 * @param array<string, mixed> $details
	 */
	public function __construct(
		string $message,
		private readonly array $details = [],
		private readonly string $errorCode = 'validation_error',
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetails(): array {
		return $this->details;
	}
}
