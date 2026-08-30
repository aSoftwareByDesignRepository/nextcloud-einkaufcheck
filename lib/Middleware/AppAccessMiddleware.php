<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Middleware;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCA\EinkaufCheck\Exception\NotFoundException;
use OCA\EinkaufCheck\Exception\RateLimitExceededException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class AppAccessMiddleware extends Middleware {
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
	) {
	}

	public function beforeController($controller, $methodName): void {
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\EinkaufCheck\\Controller\\')) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$this->accessControl->assertCanUseApp($user->getUID());
	}

	public function afterException($controller, $methodName, \Exception $exception) {
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\EinkaufCheck\\Controller\\')) {
			throw $exception;
		}

		$l = $this->l10nFactory->get(Application::APP_ID);

		if ($exception instanceof AppAccessDeniedException) {
			return $this->accessDeniedResponse($controller, $exception, $l);
		}
		if ($exception instanceof ValidationException) {
			$code = $exception->getErrorCode();
			$status = match ($code) {
				'fetch_failed', 'fetch_busy' => Http::STATUS_BAD_GATEWAY,
				'offers_stale', 'self_lockout', 'list_busy', 'watch_busy', 'list_full', 'watch_full' => Http::STATUS_CONFLICT,
				default => Http::STATUS_BAD_REQUEST,
			};
			$message = match ($code) {
				'fetch_failed', 'fetch_busy' => $l->t('Could not load offers. Try again in a moment.'),
				'offers_stale' => $l->t('No cached offers yet. Refresh to load prices from the stores.'),
				'self_lockout' => $l->t('That change would lock you out of EinkaufCheck. It was not saved.'),
				'list_busy' => $l->t('Shopping list is busy. Try again.'),
				'watch_busy' => $l->t('Watch list is busy. Try again.'),
				'invalid_plz' => $l->t('Postal code must be exactly 5 digits.'),
				'invalid_week' => $l->t('Week must be this week or next week.'),
				'query_length' => $l->t('Query must be between 3 and 200 characters.'),
				'invalid_store' => $l->t('Store must be empty, ALDI Nord, or Lidl.'),
				'invalid_price' => $l->t('Price must be between 0 and 9999.99.'),
				'invalid_qty' => $l->t('Quantity must be between 1 and 99.'),
				'item_name_required' => $l->t('Item name is required.'),
				'list_full' => $l->t('Shopping list is full (200 items).'),
				'watch_full' => $l->t('Watch list is full (50 items).'),
				'search_too_short' => $l->t('Type at least 2 characters to search.'),
				'invalid_access_mode' => $l->t('Access mode must be open or restricted.'),
				'unknown_group' => $l->t('One or more groups do not exist.'),
				'unknown_user' => $l->t('One or more people do not exist.'),
				'invalid_bool' => $l->t('That field must be yes or no.'),
				default => $l->t('Please check your input and try again.'),
			};
			return $this->envelope($code, $message, $status, $exception->getDetails());
		}
		if ($exception instanceof NotFoundException) {
			return $this->envelope(
				$exception->getErrorCode(),
				$l->t('That item was not found.'),
				Http::STATUS_NOT_FOUND,
			);
		}
		if ($exception instanceof RateLimitExceededException) {
			return $this->envelope(
				$exception->getErrorCode(),
				$l->t('Too many requests. Please wait a moment and try again.'),
				Http::STATUS_TOO_MANY_REQUESTS,
			);
		}

		throw $exception;
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private function envelope(string $code, string $message, int $status, array $details = []): JSONResponse {
		return new JSONResponse([
			'error' => [
				'code' => $code,
				'message' => $message,
				'details' => $details,
			],
		], $status);
	}

	private function accessDeniedResponse(object $controller, AppAccessDeniedException $exception, IL10N $l): JSONResponse|TemplateResponse {
		if ($this->wantsJson($controller)) {
			$message = $exception->getReason() === 'admin_required'
				? $l->t('Only an EinkaufCheck administrator may do that.')
				: $l->t('You are not allowed to use EinkaufCheck.');
			return $this->envelope(
				$exception->getErrorCode(),
				$message,
				Http::STATUS_FORBIDDEN,
				$exception->getDetails(),
			);
		}

		$restricted = $exception->getReason() === AccessControlService::DENIAL_RESTRICTED;
		$message = $restricted
			? $l->t('Your organisation restricts EinkaufCheck access. You are not on the allow-list.')
			: $l->t('You are not allowed to use EinkaufCheck right now.');
		$hint = $restricted
			? $l->t('Ask a Nextcloud or EinkaufCheck administrator to add you in Settings → Access.')
			: $l->t('If you believe this is a mistake, contact your EinkaufCheck administrator.');

		$response = new TemplateResponse(
			Application::APP_ID,
			'access-denied',
			[
				'message' => $message,
				'hint' => $hint,
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			],
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}

	private function wantsJson(object $controller): bool {
		if ($controller instanceof ApiController) {
			return true;
		}
		$accept = strtolower((string)$this->request->getHeader('Accept'));
		if (str_contains($accept, 'application/json')) {
			return true;
		}
		$path = (string)($this->request->getPathInfo() ?? '');
		$uri = (string)$this->request->getRequestUri();
		return str_starts_with($path, '/apps/einkaufcheck/api')
			|| str_starts_with($uri, '/apps/einkaufcheck/api')
			|| str_contains($uri, '/apps/einkaufcheck/api')
			|| str_starts_with($path, '/api');
	}
}
