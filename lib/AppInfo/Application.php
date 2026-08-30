<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\AppInfo;

use OCA\EinkaufCheck\Listener\CSPListener;
use OCA\EinkaufCheck\Listener\GroupDeletedListener;
use OCA\EinkaufCheck\Listener\UserDeletedListener;
use OCA\EinkaufCheck\Middleware\AppAccessMiddleware;
use OCA\EinkaufCheck\Notification\Notifier;
use OCA\EinkaufCheck\Service\OfferFetchService;
use OCA\EinkaufCheck\Service\PriceHistoryService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'einkaufcheck';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
		$context->registerMiddleware(AppAccessMiddleware::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);

		$context->registerService(OfferFetchService::class, function ($c): OfferFetchService {
			$appData = null;
			try {
				$appData = $c->get(IAppDataFactory::class)->get(self::APP_ID);
			} catch (\Throwable) {
				// AppData unavailable — OfferFetchService falls back to appconfig.
			}
			$history = null;
			try {
				$history = $c->get(PriceHistoryService::class);
			} catch (\Throwable) {
				// History optional — fetch must still succeed if the table is missing.
			}
			return new OfferFetchService(
				$c->get(IConfig::class),
				$c->get(ILockingProvider::class),
				$c->get(ITimeFactory::class),
				$appData,
				$c->get(LoggerInterface::class),
				$history,
			);
		});
	}

	public function boot(IBootContext $context): void {
	}
}
