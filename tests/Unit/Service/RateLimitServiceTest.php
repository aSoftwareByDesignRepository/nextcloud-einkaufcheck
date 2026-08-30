<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\AppAccessDeniedException;
use OCA\EinkaufCheck\Exception\RateLimitExceededException;
use OCA\EinkaufCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RateLimitServiceTest extends TestCase {
	/** @var IConfig&MockObject */
	private IConfig $config;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var array<string, string> */
	private array $prefs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->prefs = [];
		$this->config = $this->createMock(IConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->locking = $this->createMock(ILockingProvider::class);

		$this->config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, $default = '') {
				self::assertSame(Application::APP_ID, $app);
				self::assertLessThanOrEqual(RateLimitService::CONFIG_KEY_MAX_LENGTH, strlen($key));
				return $this->prefs[$userId . "\0" . $key] ?? (string)$default;
			}
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, $value): void {
				self::assertSame(Application::APP_ID, $app);
				self::assertLessThanOrEqual(RateLimitService::CONFIG_KEY_MAX_LENGTH, strlen($key));
				self::assertLessThanOrEqual(64, strlen($userId));
				$this->prefs[$userId . "\0" . $key] = (string)$value;
			}
		);
		$this->locking->method('acquireLock');
		$this->locking->method('releaseLock');
	}

	private function service(): RateLimitService {
		return new RateLimitService($this->config, $this->time, $this->locking);
	}

	public function testUuidStyleUserIdKeyFitsUnder64(): void {
		$uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$key = $this->service()->preferenceKey('offers_refresh');
		self::assertSame('rate_limit:offers_refresh', $key);
		self::assertLessThanOrEqual(64, strlen($key));
		self::assertStringNotContainsString($uuid, $key);
		$legacy = 'rate_limit:offers_refresh:' . $uuid;
		self::assertGreaterThan(strlen($key), strlen($legacy));

		$this->time->method('getTime')->willReturn(1_700_000_000);
		$this->service()->assertAllowed($uuid, 'offers_refresh', 6, 3600);
		self::assertArrayHasKey($uuid . "\0" . $key, $this->prefs);
	}

	public function testAllowsUnderMaxAndRecordsHit(): void {
		$now = 1_700_000_200;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['alice' . "\0" . 'rate_limit:list_write'] = json_encode([$now - 10], JSON_THROW_ON_ERROR);

		$this->locking->expects(self::once())
			->method('acquireLock')
			->with(self::callback(static function (string $key): bool {
				return str_starts_with($key, 'ekc-rl-')
					&& strlen($key) <= 64
					&& strlen($key) === strlen('ekc-rl-') + 32;
			}), ILockingProvider::LOCK_EXCLUSIVE);
		$this->locking->expects(self::once())->method('releaseLock');

		$this->service()->assertAllowed('alice', 'list_write', 120, 3600);
		$entries = json_decode($this->prefs['alice' . "\0" . 'rate_limit:list_write'], true, 512, JSON_THROW_ON_ERROR);
		self::assertCount(2, $entries);
		self::assertSame($now, end($entries));
	}

	public function testThrowsWhenWindowFullAndDoesNotWrite(): void {
		$now = 1_700_000_300;
		$this->time->method('getTime')->willReturn($now);
		$key = 'bob' . "\0" . 'rate_limit:list_export';
		$this->prefs[$key] = json_encode([$now, $now - 1, $now - 2], JSON_THROW_ON_ERROR);
		$before = $this->prefs[$key];

		try {
			$this->service()->assertAllowed('bob', 'list_export', 3, 3600);
			self::fail('expected RateLimitExceededException');
		} catch (RateLimitExceededException) {
			self::assertSame($before, $this->prefs[$key]);
		}
	}

	public function testPrunesStaleEntriesOutsideWindow(): void {
		$now = 1_700_000_500;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['dave' . "\0" . 'rate_limit:watch_write'] = json_encode(
			[$now - 10_000, $now - 10],
			JSON_THROW_ON_ERROR
		);
		$this->service()->assertAllowed('dave', 'watch_write', 60, 3600);
		self::assertSame(
			[$now - 10, $now],
			json_decode($this->prefs['dave' . "\0" . 'rate_limit:watch_write'], true, 512, JSON_THROW_ON_ERROR)
		);
	}

	public function testCorruptJsonStartsFreshWindow(): void {
		$now = 1_700_000_600;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['erin' . "\0" . 'rate_limit:directory_search'] = 'not-json{';
		$this->service()->assertAllowed('erin', 'directory_search', 60, 60);
		self::assertSame(
			[$now],
			json_decode($this->prefs['erin' . "\0" . 'rate_limit:directory_search'], true, 512, JSON_THROW_ON_ERROR)
		);
	}

	public function testEmptyAndOversizedUserIdDenied(): void {
		$this->expectException(AppAccessDeniedException::class);
		$this->service()->assertAllowed('', 'list_write', 10, 60);
	}

	public function testOversizedUserIdDenied(): void {
		$this->expectException(AppAccessDeniedException::class);
		$this->service()->assertAllowed(str_repeat('x', 65), 'list_write', 10, 60);
	}

	public function testInvalidActionDenied(): void {
		$this->expectException(AppAccessDeniedException::class);
		$this->service()->assertAllowed('admin', 'evil:action/../x', 10, 60);
	}

	public function testContestedLockFailsClosed(): void {
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->locking->expects(self::exactly(2))
			->method('acquireLock')
			->willThrowException(new LockedException('busy'));
		$this->locking->expects(self::never())->method('releaseLock');
		$this->expectException(RateLimitExceededException::class);
		$this->service()->assertAllowed('admin', 'offers_refresh', 6, 3600);
	}

	public function testReleasesLockEvenWhenLimited(): void {
		$now = 1_700_000_900;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['hank' . "\0" . 'rate_limit:access_write'] = json_encode([$now, $now], JSON_THROW_ON_ERROR);
		$this->locking->expects(self::once())->method('acquireLock');
		$this->locking->expects(self::once())->method('releaseLock');
		try {
			$this->service()->assertAllowed('hank', 'access_write', 2, 3600);
			self::fail('expected RateLimitExceededException');
		} catch (RateLimitExceededException) {
			// expected
		}
	}

	public function testUsersAreIsolated(): void {
		$now = 1_700_001_100;
		$this->time->method('getTime')->willReturn($now);
		$a = '11111111-2222-3333-4444-555555555555';
		$b = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$this->service()->assertAllowed($a, 'offers_read', 1, 3600);
		$this->service()->assertAllowed($b, 'offers_read', 1, 3600);
		self::assertArrayHasKey($a . "\0rate_limit:offers_read", $this->prefs);
		self::assertArrayHasKey($b . "\0rate_limit:offers_read", $this->prefs);
	}

	public function testRetryAcquireSucceedsAfterFirstLockFailure(): void {
		$now = 1_700_001_300;
		$this->time->method('getTime')->willReturn($now);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->locking->expects(self::exactly(2))
			->method('acquireLock')
			->willReturnOnConsecutiveCalls(
				self::throwException(new LockedException('busy')),
				null
			);
		$this->locking->expects(self::once())->method('releaseLock');
		$this->service()->assertAllowed('kate', 'settings_write', 30, 3600);
		self::assertArrayHasKey('kate' . "\0rate_limit:settings_write", $this->prefs);
	}

	public function testKnownActionKeysStayUnderLimit(): void {
		$svc = $this->service();
		foreach (['offers_read', 'offers_refresh', 'offers_fetch', 'list_export', 'directory_search', 'watch_hits'] as $action) {
			$key = $svc->preferenceKey($action);
			self::assertLessThanOrEqual(RateLimitService::CONFIG_KEY_MAX_LENGTH, strlen($key), $action);
			self::assertStringStartsWith('rate_limit:', $key);
		}
	}
}
