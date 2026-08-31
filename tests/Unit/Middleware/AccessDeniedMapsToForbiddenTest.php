<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Tests\Unit\Middleware;

use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Middleware\AppAccessMiddleware;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class AccessDeniedMapsToForbiddenTest extends TestCase {
	private function middleware(): AppAccessMiddleware {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		return new AppAccessMiddleware(
			$this->createMock(IUserSession::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(IRequest::class),
			$this->createMock(IURLGenerator::class),
			$factory,
		);
	}

	public function testAccessDeniedBecomes403Envelope(): void {
		$mw = $this->middleware();
		$controller = $this->createMock(ApiController::class);
		$response = $mw->afterException($controller, 'listGet', new AccessDeniedException());
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		self::assertSame('access_denied', $data['error']['code'] ?? null);
	}

	public function testInvalidArgumentBecomes400Envelope(): void {
		$mw = $this->middleware();
		$controller = $this->createMock(ApiController::class);
		$response = $mw->afterException(
			$controller,
			'workspacesCreate',
			new \InvalidArgumentException('privacy_mode must be standard or private.'),
		);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertSame('invalid_argument', $data['error']['code'] ?? null);
	}

	public function testValidationExceptionListFullStillConflict(): void {
		$mw = $this->middleware();
		$controller = $this->createMock(ApiController::class);
		$response = $mw->afterException(
			$controller,
			'listAdd',
			new \OCA\EinkaufCheck\Exception\ValidationException('full', [], 'list_full'),
		);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('list_full', $response->getData()['error']['code'] ?? null);
	}
}
