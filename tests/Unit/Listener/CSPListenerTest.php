<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Listener;

use OCA\EinkaufCheck\Listener\CSPListener;
use OCA\EinkaufCheck\Service\OfferImagePolicy;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use PHPUnit\Framework\TestCase;

class CSPListenerTest extends TestCase {
	public function testOnlyEinkaufCheckPathsMatch(): void {
		self::assertTrue($this->listenerFor('/apps/einkaufcheck')->isEinkaufCheckRequest());
		self::assertTrue($this->listenerFor('/apps/einkaufcheck/offers')->isEinkaufCheckRequest());
		self::assertTrue($this->listenerFor('/index.php/apps/einkaufcheck/trends')->isEinkaufCheckRequest());
		self::assertFalse($this->listenerFor('/apps/einkaufcheckevil')->isEinkaufCheckRequest());
		self::assertFalse($this->listenerFor('/apps/files')->isEinkaufCheckRequest());
		self::assertFalse($this->listenerFor('/apps/files/einkaufcheck')->isEinkaufCheckRequest());
	}

	public function testAddsImageDomainsOnAppPagesOnly(): void {
		$event = $this->createMock(AddContentSecurityPolicyEvent::class);
		$event->expects($this->once())->method('addPolicy');
		$this->listenerFor('/apps/einkaufcheck/')->handle($event);

		$other = $this->createMock(AddContentSecurityPolicyEvent::class);
		$other->expects($this->never())->method('addPolicy');
		$this->listenerFor('/apps/files')->handle($other);

		$ignored = $this->createMock(Event::class);
		$this->listenerFor('/apps/einkaufcheck/')->handle($ignored);
	}

	public function testPolicyHostsStayHttpsAllowlist(): void {
		foreach (OfferImagePolicy::cspImageDomains() as $domain) {
			self::assertStringStartsWith('https://', $domain);
		}
		$app = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		self::assertStringContainsString('AddContentSecurityPolicyEvent::class, CSPListener::class', $app);
	}

	private function listenerFor(string $path): CSPListener {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn($path);
		return new CSPListener($request);
	}
}
