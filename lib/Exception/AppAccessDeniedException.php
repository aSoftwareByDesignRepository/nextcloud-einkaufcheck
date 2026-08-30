<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Exception;

/**
 * Logged-in user may not enter the EinkaufCheck app shell. HTTP 403.
 */
class AppAccessDeniedException extends \RuntimeException {
	public function __construct(
		string $message = 'You are not allowed to use this app.',
		private readonly ?string $reason = null,
	) {
		parent::__construct($message);
	}

	public function getReason(): ?string {
		return $this->reason;
	}

	public function getErrorCode(): string {
		return $this->reason === 'admin_required' ? 'admin_required' : 'app_access_denied';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetails(): array {
		return $this->reason !== null ? ['reason' => $this->reason] : [];
	}
}
