<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Controller;

use OCA\TimeTrackerCards\AppInfo\Application;
use OCA\TimeTrackerCards\Exception\ForbiddenException;
use OCA\TimeTrackerCards\Exception\NotFoundException;
use OCA\TimeTrackerCards\Service\TimerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class TimerController extends Controller {
	public function __construct(
		IRequest $request,
		private TimerService $service,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->run(fn () => $this->service->index($this->uid()));
	}

	#[NoAdminRequired]
	public function create(string $name = '', string $description = '', int $minutes = 45, bool $resettable = true, string $timezone = 'UTC'): JSONResponse {
		return $this->run(fn () => $this->service->create($this->uid(), $name, $description, $minutes, $resettable, $timezone));
	}

	#[NoAdminRequired]
	public function update(int $id, string $name = '', string $description = '', int $minutes = 45, bool $resettable = true, string $timezone = ''): JSONResponse {
		return $this->run(fn () => $this->service->update($id, $this->uid(), $name, $description, $minutes, $resettable, $timezone));
	}

	#[NoAdminRequired]
	public function delete(int $id): JSONResponse {
		return $this->run(fn () => $this->service->delete($id, $this->uid()));
	}

	#[NoAdminRequired]
	public function start(int $id): JSONResponse {
		return $this->run(fn () => $this->service->start($id, $this->uid()));
	}

	#[NoAdminRequired]
	public function pause(int $id): JSONResponse {
		return $this->run(fn () => $this->service->pause($id, $this->uid()));
	}

	#[NoAdminRequired]
	public function reset(int $id): JSONResponse {
		return $this->run(fn () => $this->service->reset($id, $this->uid()));
	}

	#[NoAdminRequired]
	public function leave(int $id): JSONResponse {
		return $this->run(fn () => $this->service->leave($id, $this->uid()));
	}

	#[NoAdminRequired]
	public function invite(int $id, string $uid = ''): JSONResponse {
		return $this->run(fn () => $this->service->invite($id, $this->uid(), $uid));
	}

	#[NoAdminRequired]
	public function removeMember(int $id, string $uid): JSONResponse {
		return $this->run(fn () => $this->service->removeMember($id, $this->uid(), $uid));
	}

	#[NoAdminRequired]
	public function searchUsers(string $search = '', int $timerId = 0): JSONResponse {
		return $this->run(fn () => ['users' => $this->service->searchUsers($this->uid(), $search, $timerId)]);
	}

	#[NoAdminRequired]
	public function setOrder(array $ids = []): JSONResponse {
		return $this->run(fn () => $this->service->setOrder($this->uid(), $ids));
	}

	private function uid(): string {
		return (string)$this->userId;
	}

	private function run(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
