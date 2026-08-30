<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Exception;

/**
 * Authenticated caller may use the app, but the resource does not exist. HTTP 404.
 */
class NotFoundException extends \RuntimeException {
	public function __construct(
		string $message = 'Resource not found.',
		private readonly string $errorCode = 'not_found',
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
		return [];
	}
}
