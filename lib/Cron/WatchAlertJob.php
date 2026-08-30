<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Cron;

use OCA\EinkaufCheck\Service\AlertService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class WatchAlertJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly AlertService $alerts,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Alle 6 Stunden — neue Wochenangebote oft sonntags/montags.
		$this->setInterval(6 * 3600);
		$this->setAllowParallelRuns(false);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$result = $this->alerts->runAll();
			$this->logger->info('EinkaufCheck watch alerts', $result);
		} catch (\Throwable $e) {
			$this->logger->warning('EinkaufCheck watch alert job failed', ['exception' => $e]);
		}
	}
}
