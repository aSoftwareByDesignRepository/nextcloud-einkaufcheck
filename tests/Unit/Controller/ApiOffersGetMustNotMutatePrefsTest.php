<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Controller;

use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\OfferUnitPrice;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchMatchService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * GET must be side-effect-free. Saving PLZ/week on GET is CSRF-writable
 * preference mutation and is forbidden.
 */
class ApiOffersGetMustNotMutatePrefsTest extends TestCase {
	public function testGetOffersNeverSavesPrefsEvenWhenQueryDiffers(): void {
		$controller = $this->controller(
			function (OfferFetchService $offers): void {
				$offers->expects($this->never())->method('saveUserPrefs');
				$offers->expects($this->never())->method('fetch');
				$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
				$offers->method('peekCache')->willReturnCallback(
					static function (string $plz, string $week): array {
						self::assertSame('80331', $plz);
						self::assertContains($week, ['current', 'next']);
						return ['offers' => []];
					}
				);
			},
			['plz' => '80331', 'week' => 'next', 'refresh' => '0'],
		);
		$controller->offers();
	}

	public function testGetOffersIgnoresRefreshFlagAndDoesNotLiveFetch(): void {
		$controller = $this->controller(
			function (OfferFetchService $offers): void {
				$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
				$offers->expects($this->never())->method('fetch');
				$offers->expects($this->exactly(2))->method('peekCache')->willReturnCallback(
					static function (string $plz, string $week): array {
						self::assertSame('24149', $plz);
						self::assertContains($week, ['current', 'next']);
						return ['offers' => []];
					}
				);
			},
			['plz' => '24149', 'week' => 'current', 'refresh' => '1'],
		);
		$controller->offers();
	}

	public function testGetOffersAnnotatesWeekCompareWithoutLiveFetch(): void {
		$milk = [
			'store' => 'ALDI Nord',
			'brand' => 'Milsani',
			'name' => 'Milch',
			'pack' => '1 l',
			'price' => 1.19,
			'per_l' => 1.19,
		];
		$controller = $this->controller(
			function (OfferFetchService $offers) use ($milk): void {
				$offers->expects($this->never())->method('saveUserPrefs');
				$offers->expects($this->never())->method('fetch');
				$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
				$offers->method('peekCache')->willReturnCallback(
					static function (string $plz, string $week) use ($milk): array {
						self::assertSame('24149', $plz);
						if ($week === 'current') {
							return ['offers' => [$milk]];
						}
						self::assertSame('next', $week);
						$cheaper = $milk;
						$cheaper['price'] = 0.89;
						$cheaper['per_l'] = 0.89;
						return ['offers' => [$cheaper]];
					}
				);
			},
			['plz' => '24149', 'week' => 'current'],
		);
		$data = $controller->offers()->getData();
		self::assertSame('next', $data['week_compare']['other_week']);
		self::assertSame('hit', $data['week_compare']['other_cache']);
		self::assertSame('cheaper_later', $data['offers'][0]['week_tip']['verdict']);
		self::assertSame(OfferUnitPrice::KIND_L, $data['offers'][0]['unit_kind']);
		self::assertEqualsWithDelta(1.19, $data['offers'][0]['unit_price'], 0.001);
	}

	public function testGetOffersCacheMissThrowsWithoutLiveFetch(): void {
		$controller = $this->controller(
			function (OfferFetchService $offers): void {
				$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
				$offers->expects($this->never())->method('fetch');
				$offers->method('peekCache')->willReturn(null);
			},
			['plz' => '24149', 'week' => 'current'],
		);
		$this->expectException(\OCA\EinkaufCheck\Exception\ValidationException::class);
		$controller->offers();
	}

	public function testRefreshSavesPrefsThenLiveFetches(): void {
		$controller = $this->controller(
			function (OfferFetchService $offers): void {
				$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
				$offers->expects($this->once())->method('saveUserPrefs')->with('alice', '80331', 'next')
					->willReturn(['plz' => '80331', 'week' => 'next']);
				$offers->expects($this->once())->method('fetch')->with('80331', 'next', true)
					->willReturn(['offers' => []]);
				$offers->expects($this->once())->method('peekCache')->with('80331', 'current')
					->willReturn(null);
			},
			['plz' => '80331', 'week' => 'next'],
		);
		$controller->offersRefresh();
	}

	public function testStoresStatusGoesThroughAccessDoor(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('assertCanUseApp')->with('alice');
		$offers = $this->createMock(OfferFetchService::class);
		$offers->method('storesStatus')->willReturn([]);

		$controller = new ApiController(
			$this->createMock(IRequest::class),
			$offers,
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$this->createMock(RateLimitService::class),
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			new WeekCompareService(new WatchMatchService()),
		);
		$controller->storesStatus();
	}

	public function testGetTrendsNeverSavesPrefsOrLiveFetchesWhenCacheEmpty(): void {
		$history = $this->createMock(PriceHistoryService::class);
		$history->expects($this->once())->method('summarize')
			->with('80331', 'next', [], $this->anything(), '', '')
			->willReturn([
				'staples' => [],
				'cheap_now' => [],
				'search' => [],
				'weeks_tracked' => 0,
				'cache' => 'empty',
			]);

		$controller = $this->controller(
			function (OfferFetchService $offers): void {
				$offers->expects($this->never())->method('saveUserPrefs');
				$offers->expects($this->never())->method('fetch');
				$offers->method('getUserPrefs')->willReturn(['plz' => '24149', 'week' => 'current']);
				$offers->method('peekCache')->with('80331', 'next')->willReturn(null);
			},
			['plz' => '80331', 'week' => 'next'],
			$history,
		);
		$response = $controller->trends();
		self::assertSame(200, $response->getStatus());
		self::assertSame('empty', $response->getData()['cache']);
	}

	/**
	 * @param callable(OfferFetchService):void $configureOffers
	 * @param array<string, string> $params
	 */
	private function controller(
		callable $configureOffers,
		array $params,
		?PriceHistoryService $history = null,
	): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');

		$offers = $this->createMock(OfferFetchService::class);
		$configureOffers($offers);

		$watch = $this->createMock(WatchService::class);
		$watch->method('hitsForUser')->willReturn([]);
		$watch->method('list')->willReturn([]);

		$rl = $this->createMock(RateLimitService::class);
		$rl->method('assertAllowed');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return $params[$key] ?? $default;
			}
		);

		return new ApiController(
			$request,
			$offers,
			$this->createMock(ShoppingListService::class),
			$watch,
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$history ?? $this->createMock(PriceHistoryService::class),
			new WeekCompareService(new WatchMatchService()),
		);
	}
}
