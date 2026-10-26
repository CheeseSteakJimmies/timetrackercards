<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Listener;

use OCA\TimeTrackerCards\Service\TimerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private TimerService $service,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		$this->service->deleteUserData($event->getUser()->getUID());
	}
}
