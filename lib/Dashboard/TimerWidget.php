<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Dashboard;

use OCA\TimeTrackerCards\AppInfo\Application;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Util;

/**
 * A compact view of the user's timers for the Nextcloud dashboard: a small ring,
 * the name, the time left and a start/pause button for every timer.
 * The user interface itself is drawn by js/timetrackercards-widget.js.
 */
class TimerWidget implements IWidget, IIconWidget {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		return 'Time Tracker Cards';
	}

	public function getOrder(): int {
		return 40;
	}

	public function getIconClass(): string {
		return 'icon-timetrackercards';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
		);
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRoute('timetrackercards.page.index');
	}

	public function load(): void {
		$l = $this->l10nFactory->get(Application::APP_ID);

		// The script reads its settings and its (translated) texts from this tag.
		$config = json_encode([
			'api' => $this->urlGenerator->linkToRoute('timetrackercards.timer.index'),
			'url' => $this->getUrl(),
			'labels' => [
				'start' => $l->t('Start'),
				'pause' => $l->t('Pause'),
				'resume' => $l->t('Resume'),
				'empty' => $l->t('No timers yet'),
				'showAll' => $l->t('Show all'),
				'error' => $l->t('Could not reach the server.'),
			],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		Util::addHeader('meta', [
			'name' => 'timetrackercards-widget',
			'content' => $config === false ? '{}' : $config,
		]);

		Util::addStyle(Application::APP_ID, 'style');
		Util::addStyle(Application::APP_ID, 'widget');
		Util::addScript(Application::APP_ID, 'timetrackercards-widget');
	}
}
