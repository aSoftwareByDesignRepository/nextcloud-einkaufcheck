<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Controller;

use OCA\EinkaufCheck\Controller\ApiController;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\DirectorySearchService;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCA\EinkaufCheck\Service\SettingsService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WatchService;
use OCA\EinkaufCheck\Service\WeekCompareService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ApiSettingsShowImagesTest extends TestCase {
	public function testOmittedShowImagesPassesNullSoToggleIsPreserved(): void {
		$offers = $this->createMock(OfferFetchService::class);
		$offers->expects($this->once())->method('saveUserPrefs')
			->with('alice', '24149', 'current', null)
			->willReturn(['plz' => '24149', 'week' => 'current', 'show_images' => true]);

		$controller = $this->controller($offers, [
			'plz' => '24149',
			'week' => 'current',
		]);
		$data = $controller->settingsSave()->getData();
		self::assertTrue($data['show_images']);
	}

	public function testFalseStringIsFalse(): void {
		$offers = $this->createMock(OfferFetchService::class);
		$offers->expects($this->once())->method('saveUserPrefs')
			->with('alice', '24149', 'current', false)
			->willReturn(['plz' => '24149', 'week' => 'current', 'show_images' => false]);

		$controller = $this->controller($offers, [
			'plz' => '24149',
			'week' => 'current',
			'show_images' => 'false',
		]);
		$data = $controller->settingsSave()->getData();
		self::assertFalse($data['show_images']);
	}

	public function testGarbageShowImagesIsRejected(): void {
		$offers = $this->createMock(OfferFetchService::class);
		$offers->expects($this->never())->method('saveUserPrefs');
		$controller = $this->controller($offers, [
			'plz' => '24149',
			'week' => 'current',
			'show_images' => 'maybe',
		]);
		$this->expectException(ValidationException::class);
		$controller->settingsSave();
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(OfferFetchService $offers, array $params): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(AccessControlService::class);
		$access->method('assertCanUseApp');
		$rl = $this->createMock(RateLimitService::class);
		$rl->method('assertAllowed');
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new ApiController(
			$request,
			$offers,
			$this->createMock(ShoppingListService::class),
			$this->createMock(WatchService::class),
			$session,
			$rl,
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(DirectorySearchService::class),
			$this->createMock(PriceHistoryService::class),
			$this->createMock(WeekCompareService::class),
		);
	}
}
