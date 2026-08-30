<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\WatchMatchService;
use PHPUnit\Framework\TestCase;

class WatchMatchServiceTest extends TestCase {
	private WatchMatchService $match;

	protected function setUp(): void {
		$this->match = new WatchMatchService();
	}

	public function testSubstringMatchUnderPriceCap(): void {
		$watch = ['id' => 1, 'query' => 'schlagsahne', 'max_price' => 1.0, 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'Schlagsahne 30%', 'price' => 0.66, 'per_kg' => 3.3];
		$this->assertTrue($this->match->matches($watch, $offer));
	}

	public function testPriceCapFailsClosed(): void {
		$watch = ['id' => 1, 'query' => 'schlagsahne', 'max_price' => 0.5, 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'Schlagsahne', 'price' => 0.66];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testKgCapWithoutPerKgFailsClosed(): void {
		$watch = ['id' => 1, 'query' => 'bananen', 'max_per_kg' => 1.5, 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Bananen', 'price' => 1.19];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testStoreFilter(): void {
		$watch = ['id' => 1, 'query' => 'bananen', 'store' => 'Lidl', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Bananen', 'price' => 1.19];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testTokenSubsetMatchIgnoresNoiseAndOrder(): void {
		$watch = ['id' => 1, 'query' => 'bananen bio', 'enabled' => true];
		$offer = ['store' => 'Lidl', 'brand' => '', 'name' => 'Bio Bananen', 'price' => 1.29, 'per_kg' => 1.29];
		$this->assertTrue($this->match->matches($watch, $offer));
	}

	public function testMissingPriceFailsClosedOnPriceCap(): void {
		$watch = ['id' => 1, 'query' => 'milch', 'max_price' => 1.0, 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'Milch', 'price' => null];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testEisMustNotMatchReis(): void {
		$watch = ['id' => 1, 'query' => 'eis', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Reis', 'price' => 1.19];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testTwoTokenQueryDoesNotMatchOneTokenOffer(): void {
		$watch = ['id' => 1, 'query' => 'apfel banane', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Äpfel', 'price' => 1.19];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testTwoCharFragmentDoesNotMatchBrandInfix(): void {
		$watch = ['id' => 1, 'query' => 'si', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'Milch', 'price' => 0.89];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testDisabledWatchSkipped(): void {
		$hits = $this->match->hits(
			[['id' => 1, 'query' => 'bananen', 'enabled' => false]],
			[['store' => 'ALDI Nord', 'name' => 'Bananen', 'brand' => '', 'price' => 1.0]],
		);
		$this->assertSame([], $hits);
	}

	public function testMilchMatchesHMilchByWordBoundary(): void {
		$watch = ['id' => 1, 'query' => 'milch', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => 'Milsani', 'name' => 'H-Milch', 'price' => 0.89];
		$this->assertTrue($this->match->matches($watch, $offer));
	}

	public function testApfelMatchesUmlautApples(): void {
		$watch = ['id' => 1, 'query' => 'apfel', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Äpfel', 'price' => 1.19];
		$this->assertTrue($this->match->matches($watch, $offer));
	}

	public function testMilchDoesNotMatchMilchschnitte(): void {
		$watch = ['id' => 1, 'query' => 'milch', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Milchschnitte', 'price' => 1.49];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testEmptyQueryNeverMatches(): void {
		$watch = ['id' => 1, 'query' => '', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Milch', 'price' => 0.89];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testSoloBioNoiseNeverMatches(): void {
		$watch = ['id' => 1, 'query' => 'bio', 'enabled' => true];
		$offer = ['store' => 'Lidl', 'brand' => '', 'name' => 'Bio Bananen', 'price' => 1.29, 'per_kg' => 1.29];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testSoloPlusNoiseNeverMatches(): void {
		$watch = ['id' => 1, 'query' => 'plus', 'enabled' => true];
		$offer = ['store' => 'Lidl', 'brand' => '', 'name' => 'Lidl Plus Milch', 'price' => 0.89];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testSoloFrischNoiseNeverMatches(): void {
		$watch = ['id' => 1, 'query' => 'frisch', 'enabled' => true];
		$offer = ['store' => 'ALDI Nord', 'brand' => '', 'name' => 'Frische Milch', 'price' => 0.99];
		$this->assertFalse($this->match->matches($watch, $offer));
	}

	public function testHitsCapPerWatch(): void {
		$offers = [];
		for ($i = 0; $i < WatchMatchService::MAX_HITS_PER_WATCH + 5; $i++) {
			$offers[] = [
				'store' => 'ALDI Nord',
				'brand' => '',
				'name' => 'Bananen ' . $i,
				'price' => 1.0,
			];
		}
		$hits = $this->match->hits(
			[['id' => 7, 'query' => 'bananen', 'enabled' => true]],
			$offers,
		);
		$this->assertCount(WatchMatchService::MAX_HITS_PER_WATCH, $hits);
	}

	public function testHitsCapTotalAcrossWatches(): void {
		$offers = [];
		for ($i = 0; $i < WatchMatchService::MAX_HITS_PER_WATCH; $i++) {
			$offers[] = [
				'store' => 'ALDI Nord',
				'brand' => '',
				'name' => 'Bananen ' . $i,
				'price' => 1.0,
			];
		}
		$watches = [];
		for ($w = 1; $w <= 8; $w++) {
			$watches[] = ['id' => $w, 'query' => 'bananen', 'enabled' => true];
		}
		$hits = $this->match->hits($watches, $offers);
		$this->assertCount(WatchMatchService::MAX_HITS_TOTAL, $hits);
	}
}
