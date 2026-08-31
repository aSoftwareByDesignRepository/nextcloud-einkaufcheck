<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Exception;

/**
 * Caller is authenticated but not permitted (membership, role, or resource scope).
 * Maps to HTTP 403 + {@code access_denied}. Unknown and unauthorized workspace IDs
 * share this exception so clients cannot probe existence (IDOR opacity).
 */
class AccessDeniedException extends \RuntimeException {
	public function __construct(
		string $message = 'You cannot access that shopping space.',
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string {
		return 'access_denied';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetails(): array {
		return [];
	}
}
