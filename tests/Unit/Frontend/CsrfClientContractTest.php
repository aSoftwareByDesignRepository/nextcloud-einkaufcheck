<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * AppFramework CSRF applies to GET as well as mutations. The browser client
 * must send requesttoken on every call (header; POST body; PUT/DELETE query)
 * and retry once after /csrftoken on 412.
 */
class CsrfClientContractTest extends TestCase {
	public function testApiClientSendsRequesttokenAndRetriesStaleCsrf(): void {
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/api.js');
		self::assertNotSame('', $js);
		self::assertStringContainsString('headers.requesttoken = token', $js);
		self::assertStringContainsString('if (token)', $js);
		self::assertStringContainsString("params.append('requesttoken', token)", $js);
		self::assertStringContainsString('application/x-www-form-urlencoded', $js);
		self::assertStringContainsString('withCsrfQuery', $js);
		self::assertGreaterThanOrEqual(2, substr_count($js, 'fetchUrl = withCsrfQuery'));
		self::assertStringContainsString('} else if (token) {', $js);
		self::assertStringContainsString('/csrftoken', $js);
		self::assertStringContainsString('response.status === 412', $js);
		self::assertStringContainsString('credentials: \'same-origin\'', $js);
	}

	public function testMessagingMaps412ToReloadNotRawCsrfText(): void {
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/messaging.js');
		self::assertStringContainsString('status === 412', $js);
		self::assertStringContainsString('Could not verify this request. Please reload the page.', $js);
		self::assertStringContainsString('status === 401', $js);
	}
}
