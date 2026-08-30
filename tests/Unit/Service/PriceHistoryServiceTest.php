<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCA\EinkaufCheck\Service\WatchMatchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class PriceHistoryServiceTest extends TestCase {
	private function service(?ITimeFactory $time = null): PriceHistoryService {
		$clock = $time ?? $this->createMock(ITimeFactory::class);
		if ($time === null) {
			$clock->method('getTime')->willReturn(1_700_000_000);
		}
		return new PriceHistoryService(
			$this->createMock(IDBConnection::class),
			$clock,
			new WatchMatchService(),
		);
	}

	public function testAldiIsNationwideLidIsPerPlz(): void {
		$svc = $this->service();
		self::assertSame('*', $svc->plzScope('ALDI Nord', '24149'));
		self::assertSame('24149', $svc->plzScope('Lidl', '24149'));
	}

	public function testAldiSkuIgnoresPlzLidDoesNot(): void {
		$svc = $this->service();
		$aldiA = $svc->skuKey('ALDI Nord', 'Milsani', 'Milch', '1l', $svc->plzScope('ALDI Nord', '24149'));
		$aldiB = $svc->skuKey('ALDI Nord', 'Milsani', 'Milch', '1l', $svc->plzScope('ALDI Nord', '80331'));
		$lidlA = $svc->skuKey('Lidl', 'Milbona', 'Milch', '1l', $svc->plzScope('Lidl', '24149'));
		$lidlB = $svc->skuKey('Lidl', 'Milbona', 'Milch', '1l', $svc->plzScope('Lidl', '80331'));
		self::assertSame($aldiA, $aldiB);
		self::assertNotSame($lidlA, $lidlB);
		self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $aldiA);
	}

	public function testWeekStartUsesBerlinIsoMonday(): void {
		$svc = $this->service();
		// 2023-11-14 22:13:20 UTC = Tue 23:13 Berlin (UTC+1)
		self::assertSame('2023-11-13', $svc->weekStart('current', 1_700_000_000));
		self::assertSame('2023-11-20', $svc->weekStart('next', 1_700_000_000));
		self::assertSame('2023-11-20', $svc->weekStart('current', 1_700_000_000, '2023-11-22'));
	}

	public function testSummarizeRejectsShortQuery(): void {
		$svc = $this->service();
		$this->expectException(ValidationException::class);
		$svc->summarize('24149', 'current', [], [], 'ab', '');
	}

	public function testSummarizeRejectsInvalidStore(): void {
		$svc = $this->service();
		$this->expectException(ValidationException::class);
		$svc->summarize('24149', 'current', [], [], '', 'Penny');
	}
}
