<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Notification;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Service\AlertService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'EinkaufCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		if ($notification->getSubject() !== AlertService::SUBJECT) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode !== '' ? $languageCode : null);
		/** @var array<string, mixed> $p */
		$p = $notification->getSubjectParameters();
		$query = (string)($p['query'] ?? '');
		$lines = (string)($p['lines'] ?? '');

		$notification->setParsedSubject($l->t('Staple on offer: %s', [$query !== '' ? $query : '—']));
		$notification->setParsedMessage($lines);
		$notification->setLink($this->url->linkToRouteAbsolute('einkaufcheck.page.index'));
		return $notification;
	}
}
