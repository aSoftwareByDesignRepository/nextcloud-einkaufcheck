<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Middleware;

use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Middleware\AppAccessMiddleware;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class AppAccessMiddlewareStatusTest extends TestCase {
	public function testListFullAndWatchFullAreConflict(): void {
		$mw = $this->middleware();
		$controller = $this->apiController();

		$full = $mw->afterException(
			$controller,
			'listAdd',
			new ValidationException('Shopping list is full.', ['limit' => 200], 'list_full'),
		);
		self::assertInstanceOf(JSONResponse::class, $full);
		self::assertSame(Http::STATUS_CONFLICT, $full->getStatus());
		self::assertSame('list_full', $full->getData()['error']['code'] ?? null);

		$watch = $mw->afterException(
			$controller,
			'watchAdd',
			new ValidationException('Watch list is full.', ['limit' => 50], 'watch_full'),
		);
		self::assertInstanceOf(JSONResponse::class, $watch);
		self::assertSame(Http::STATUS_CONFLICT, $watch->getStatus());
	}

	public function testInvalidStoreIsBadRequest(): void {
		$mw = $this->middleware();
		$res = $mw->afterException(
			$this->apiController(),
			'listExport',
			new ValidationException('Store must be empty, ALDI Nord, or Lidl.', ['store' => 'Invalid store'], 'invalid_store'),
		);
		self::assertInstanceOf(JSONResponse::class, $res);
		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	public function testAdminRequiredIsDistinctFromAppDoorDenial(): void {
		$mw = $this->middleware();
		$res = $mw->afterException(
			$this->apiController(),
			'accessGet',
			new \OCA\EinkaufCheck\Exception\AppAccessDeniedException(
				'App administrator required.',
				'admin_required',
			),
		);
		self::assertInstanceOf(JSONResponse::class, $res);
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		self::assertSame('admin_required', $res->getData()['error']['code'] ?? null);
		self::assertSame(
			'Only an EinkaufCheck administrator may do that.',
			$res->getData()['error']['message'] ?? null,
		);
	}

	private function middleware(): AppAccessMiddleware {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
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

	private function apiController(): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(OfferFetchService::class),
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$this->createMock(RateLimitService::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
		);
	}
}
