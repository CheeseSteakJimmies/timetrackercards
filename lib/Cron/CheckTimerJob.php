<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Cron;

use OCA\TimeTrackerCards\Service\TimerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Sends the "time is up" notification even when nobody has the app open.
 * It runs whenever the system cron runs (at most every 10 seconds), so run cron
 * every minute, or more often if you want faster notifications.
 */
class CheckTimerJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TimerService $service,
	) {
		parent::__construct($time);
		$this->setInterval(10);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->service->tickAll();
	}
}
