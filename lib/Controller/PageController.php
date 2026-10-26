<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Controller;

use OCA\TimeTrackerCards\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	private const UID_PLACEHOLDER = 'USERID_PLACEHOLDER';

	public function __construct(
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'timetrackercards-main');
		Util::addStyle(Application::APP_ID, 'style');

		return new TemplateResponse(Application::APP_ID, 'main', [
			'api' => $this->urlGenerator->linkToRoute('timetrackercards.timer.index'),
			'usersUrl' => $this->urlGenerator->linkToRoute('timetrackercards.timer.searchUsers'),
			'avatarUrl' => $this->avatarUrl(),
			'uid' => (string)$this->userId,
		]);
	}

	/** Avatars are optional: if the route is unavailable the page falls back to initials. */
	private function avatarUrl(): string {
		try {
			return $this->urlGenerator->linkToRoute('core.avatar.getAvatar', [
				'userId' => self::UID_PLACEHOLDER,
				'size' => 64,
			]);
		} catch (\Throwable) {
			return '';
		}
	}
}
