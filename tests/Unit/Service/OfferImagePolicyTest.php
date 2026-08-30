<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Service\OfferImagePolicy;
use PHPUnit\Framework\TestCase;

class OfferImagePolicyTest extends TestCase {
	public function testLiveFeedHostsAreKept(): void {
		self::assertSame(
			'https://s7g10.scene7.com/is/image/aldinord/6104_18_2026_speisekartoffeln_on_140826_105312',
			OfferImagePolicy::sanitize('https://s7g10.scene7.com/is/image/aldinord/6104_18_2026_speisekartoffeln_on_140826_105312'),
		);
		self::assertSame(
			'https://www.lidl.de/assets/gcp1a4e50af46214833bbc959fb460c8a82.jpg',
			OfferImagePolicy::sanitize('https://www.lidl.de/assets/gcp1a4e50af46214833bbc959fb460c8a82.jpg'),
		);
		self::assertSame(
			'https://static-coupons.lidlplus.com/coupon.png',
			OfferImagePolicy::sanitize('https://static-coupons.lidlplus.com/coupon.png'),
		);
		self::assertSame(
			'https://cdn.aldi-nord.de/produkt.jpg',
			OfferImagePolicy::sanitize('https://cdn.aldi-nord.de/produkt.jpg'),
		);
	}

	public function testScene7RequiresAldiNordCatalogPath(): void {
		self::assertSame('', OfferImagePolicy::sanitize('https://s7g10.scene7.com/is/image/otherbrand/tracking'));
		self::assertSame('', OfferImagePolicy::sanitize('https://s7g10.scene7.com/is/content/aldinord/x'));
	}

	public function testLookalikeHostIsRejected(): void {
		self::assertFalse(OfferImagePolicy::hostAllowed('xlidl.de'));
		self::assertFalse(OfferImagePolicy::hostAllowed('notlidl.de'));
		self::assertTrue(OfferImagePolicy::hostAllowed('lidl.de'));
		self::assertTrue(OfferImagePolicy::hostAllowed('www.lidl.de'));
		self::assertSame('', OfferImagePolicy::sanitize('https://xlidl.de/assets/x.jpg'));
	}

	public function testDangerousSchemesHostsAndPathsAreStripped(): void {
		self::assertSame('', OfferImagePolicy::sanitize('javascript:alert(1)'));
		self::assertSame('', OfferImagePolicy::sanitize('http://www.lidl.de/assets/x.jpg'));
		self::assertSame('', OfferImagePolicy::sanitize('https://user:pass@www.lidl.de/assets/x.jpg'));
		self::assertSame('', OfferImagePolicy::sanitize('https://127.0.0.1/x.jpg'));
		self::assertSame('', OfferImagePolicy::sanitize('https://evil.example/x.jpg'));
		self::assertSame('', OfferImagePolicy::sanitize('https://www.lidl.de/assets/x.svg'));
		self::assertSame('', OfferImagePolicy::sanitize('https://www.lidl.de/assets/x.js'));
		self::assertSame('', OfferImagePolicy::sanitize('https://www.lidl.de:8443/assets/x.jpg'));
		self::assertSame('', OfferImagePolicy::sanitize(''));
	}

	public function testQueryIsKeptAndFragmentDropped(): void {
		self::assertSame(
			'https://s7g10.scene7.com/is/image/aldinord/x?wid=200',
			OfferImagePolicy::sanitize('https://s7g10.scene7.com/is/image/aldinord/x?wid=200#frag'),
		);
	}

	public function testCspDomainsCoverAllowlistedSuffixes(): void {
		$domains = OfferImagePolicy::cspImageDomains();
		self::assertContains('https://*.scene7.com', $domains);
		self::assertContains('https://*.lidl.de', $domains);
		self::assertContains('https://*.lidlplus.com', $domains);
		self::assertContains('https://*.aldi-nord.de', $domains);
		self::assertNotContains('*', $domains);
		foreach ($domains as $d) {
			self::assertStringStartsWith('https://', $d);
			self::assertNotSame('https://*', $d);
		}
	}
}
