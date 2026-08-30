<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Listener;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Service\OfferImagePolicy;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Allow opt-in product pictures from retailer CDNs on EinkaufCheck pages only.
 * Never relaxes script-src. Unknown apps keep the default img-src.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CSPListener implements IEventListener {
	public function __construct(
		private readonly IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}
		if (!$this->isEinkaufCheckRequest()) {
			return;
		}
		$csp = new EmptyContentSecurityPolicy();
		foreach (OfferImagePolicy::cspImageDomains() as $domain) {
			$csp->addAllowedImageDomain($domain);
		}
		$event->addPolicy($csp);
	}

	public function isEinkaufCheckRequest(): bool {
		$path = $this->request->getPathInfo();
		if (!is_string($path) || $path === '') {
			$path = (string)$this->request->getRequestUri();
		}
		$app = '/apps/' . Application::APP_ID;
		$indexed = '/index.php' . $app;
		return $path === $app
			|| str_starts_with($path, $app . '/')
			|| $path === $indexed
			|| str_starts_with($path, $indexed . '/')
			|| str_contains($path, $app . '/')
			|| str_contains($path, $indexed . '/');
	}
}
