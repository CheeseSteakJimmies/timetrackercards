<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Service;

use OCA\TimeTrackerCards\AppInfo\Application;
use OCA\TimeTrackerCards\Db\TimerMapper;
use OCA\TimeTrackerCards\Exception\ForbiddenException;
use OCA\TimeTrackerCards\Exception\NotFoundException;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * All timer logic. A timer is stored with absolute millisecond timestamps, so
 * the countdown is identical on every device and does not depend on any open
 * browser tab.
 *
 * Two kinds of timers:
 *  - resettable: anyone with access can start, pause and reset it, and start it
 *    again after it has finished (oven timer, game session, ...)
 *  - not resettable: a daily allowance. It cannot be reset by hand, and starts
 *    over with the full time each day (in the timezone of the card).
 */
class TimerService {
	public const MAX_TIMERS_PER_USER = 50;
	public const MAX_MEMBERS_PER_TIMER = 50;
	private const MAX_MINUTES = 1440;

	/** @var array<string, string> */
	private array $names = [];

	public function __construct(
		private TimerMapper $mapper,
		private IUserManager $userManager,
		private IManager $notifications,
		private UserSearchService $userSearch,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------------ read

	public function index(string $uid): array {
		$rows = [];
		foreach ($this->mapper->findForUser($uid) as $row) {
			$rows[] = $this->tick($row);
		}
		$members = $this->mapper->membersByTimer(array_column($rows, 'id'));

		$timers = [];
		foreach ($rows as $row) {
			$timers[] = $this->view($row, $uid, $members[$row['id']] ?? []);
		}
		return ['now' => $this->nowMs(), 'timers' => $timers];
	}

	/** Called by the background job: finishes timers and sends notifications. */
	public function tickAll(): void {
		foreach ($this->mapper->findExpired($this->nowMs()) as $row) {
			$this->tick($row);
		}
	}

	// ----------------------------------------------------------------- create

	public function create(string $uid, string $name, string $description, int $minutes, bool $resettable, string $timezone): array {
		$name = $this->cleanName($name);
		$description = $this->cleanDescription($description);
		$minutes = $this->cleanMinutes($minutes);
		$timezone = $this->cleanTimezone($timezone);

		if ($this->mapper->countOwned($uid) >= self::MAX_TIMERS_PER_USER) {
			throw new \InvalidArgumentException('Too many timers');
		}

		$seconds = $minutes * 60;
		$id = $this->mapper->insert([
			'owner' => $uid,
			'name' => $name,
			'description' => $description,
			'duration' => $seconds,
			'resettable' => $resettable,
			'timezone' => $timezone,
			'status' => 'idle',
			'remaining_ms' => $seconds * 1000,
			'ends_at_ms' => 0,
			'finished_at_ms' => 0,
			'started_by' => null,
			'reset_day' => $this->today($timezone),
			'created_at' => time(),
		]);
		$this->mapper->addMember($id, $uid, $this->mapper->nextPosition($uid));

		return $this->single($this->requireMember($id, $uid), $uid);
	}

	public function update(int $id, string $uid, string $name, string $description, int $minutes, bool $resettable, string $timezone): array {
		$row = $this->tick($this->requireOwner($id, $uid));

		$name = $this->cleanName($name);
		$description = $this->cleanDescription($description);
		$seconds = $this->cleanMinutes($minutes) * 60;
		$timezone = $timezone === '' ? $row['timezone'] : $this->cleanTimezone($timezone);

		$fields = [
			'name' => $name,
			'description' => $description,
			'duration' => $seconds,
			'resettable' => $resettable,
			'timezone' => $timezone,
		];

		if ($resettable) {
			// An idle or finished timer simply gets the new length.
			if (in_array($row['status'], ['idle', 'finished'], true)) {
				$fields['status'] = 'idle';
				$fields['remaining_ms'] = $seconds * 1000;
				$fields['finished_at_ms'] = 0;
				$fields['started_by'] = null;
			} elseif ($row['status'] === 'paused') {
				$fields['remaining_ms'] = min($row['remaining_ms'], $seconds * 1000);
			}
		} else {
			// A daily allowance: editing it must never give time back or restart it.
			if ($row['status'] === 'idle') {
				$fields['remaining_ms'] = $seconds * 1000;
			} elseif ($row['status'] === 'paused') {
				$fields['remaining_ms'] = min($row['remaining_ms'], $seconds * 1000);
			}
			// Count today as already started, so it is not reset right away.
			$fields['reset_day'] = $this->today($timezone);
		}
		$this->mapper->update($id, $fields);

		return $this->single($this->requireMember($id, $uid), $uid);
	}

	public function delete(int $id, string $uid): array {
		$this->requireOwner($id, $uid);
		$this->mapper->deleteTimer($id);
		$this->clearNotifications($id);
		return ['ok' => true];
	}

	public function leave(int $id, string $uid): array {
		$row = $this->requireMember($id, $uid);
		if ($row['owner'] === $uid) {
			throw new ForbiddenException('The owner cannot leave');
		}
		$this->mapper->removeMember($id, $uid);
		return ['ok' => true];
	}

	// ---------------------------------------------------------------- control

	public function start(int $id, string $uid): array {
		$row = $this->tick($this->requireMember($id, $uid));

		if ($row['status'] === 'running') {
			return $this->single($row, $uid);
		}
		if ($row['status'] === 'finished' && !$row['resettable']) {
			throw new ForbiddenException('The time is used up for today');
		}

		$remaining = $row['status'] === 'paused' ? $row['remaining_ms'] : $row['duration'] * 1000;
		$this->mapper->update($id, [
			'status' => 'running',
			'remaining_ms' => $remaining,
			'ends_at_ms' => $this->nowMs() + $remaining,
			'finished_at_ms' => 0,
			'started_by' => $uid,
		]);
		$this->clearNotifications($id);

		return $this->single($this->requireMember($id, $uid), $uid);
	}

	public function pause(int $id, string $uid): array {
		$row = $this->tick($this->requireMember($id, $uid));

		if ($row['status'] === 'running') {
			$this->mapper->update($id, [
				'status' => 'paused',
				'remaining_ms' => max(0, $row['ends_at_ms'] - $this->nowMs()),
				'ends_at_ms' => 0,
			]);
			$row = $this->requireMember($id, $uid);
		}
		return $this->single($row, $uid);
	}

	public function reset(int $id, string $uid): array {
		$row = $this->requireMember($id, $uid);
		if (!$row['resettable']) {
			throw new ForbiddenException('This timer cannot be reset');
		}
		$this->mapper->update($id, [
			'status' => 'idle',
			'remaining_ms' => $row['duration'] * 1000,
			'ends_at_ms' => 0,
			'finished_at_ms' => 0,
			'started_by' => null,
		]);
		$this->clearNotifications($id);

		return $this->single($this->requireMember($id, $uid), $uid);
	}

	// ---------------------------------------------------------------- sharing

	public function invite(int $id, string $uid, string $invitee): array {
		$row = $this->requireOwner($id, $uid);
		$invitee = trim($invitee);

		$members = $this->mapper->membersByTimer([$id])[$id] ?? [];
		if (count($members) >= self::MAX_MEMBERS_PER_TIMER) {
			throw new \InvalidArgumentException('Too many members');
		}
		if (in_array($invitee, $members, true)) {
			return $this->single($row, $uid);
		}

		// Only allow users that the sharing settings of this server would show.
		$allowed = array_column($this->userSearch->search($invitee, $members, 10), 'uid');
		$user = $this->userManager->get($invitee);
		if ($user === null || !$user->isEnabled() || !in_array($invitee, $allowed, true)) {
			throw new NotFoundException('User not found');
		}

		$this->mapper->addMember($id, $invitee, $this->mapper->nextPosition($invitee));
		return $this->single($row, $uid);
	}

	public function removeMember(int $id, string $uid, string $target): array {
		$row = $this->requireOwner($id, $uid);
		if ($target === $row['owner']) {
			throw new ForbiddenException('The owner cannot be removed');
		}
		$this->mapper->removeMember($id, $target);
		return $this->single($row, $uid);
	}

	/** @return array<int, array{uid: string, displayName: string}> */
	public function searchUsers(string $uid, string $query, int $timerId): array {
		$exclude = [$uid];
		if ($timerId > 0) {
			$this->requireOwner($timerId, $uid);
			$exclude = array_merge($exclude, $this->mapper->membersByTimer([$timerId])[$timerId] ?? []);
		}
		return $this->userSearch->search($query, $exclude, 10);
	}

	/** @param int[] $ids timer ids in the order this user wants them */
	public function setOrder(string $uid, array $ids): array {
		$position = 0;
		foreach ($ids as $id) {
			$id = (int)$id;
			if ($id > 0 && $this->mapper->isMember($id, $uid)) {
				$this->mapper->setPosition($id, $uid, $position++);
			}
		}
		return ['ok' => true];
	}

	/** Called when a user account is deleted. */
	public function deleteUserData(string $uid): void {
		foreach ($this->mapper->ownedIds($uid) as $id) {
			$this->mapper->deleteTimer($id);
			$this->clearNotifications($id);
		}
		$this->mapper->removeUserEverywhere($uid);
	}

	// --------------------------------------------------------------- internals

	/** Applies the daily reset and finishes the timer if its time has run out. */
	private function tick(array $row): array {
		$today = $this->today($row['timezone']);

		if (!$row['resettable'] && $row['status'] !== 'running' && $row['reset_day'] !== $today) {
			$this->mapper->update($row['id'], [
				'status' => 'idle',
				'remaining_ms' => $row['duration'] * 1000,
				'ends_at_ms' => 0,
				'finished_at_ms' => 0,
				'started_by' => null,
				'reset_day' => $today,
			]);
			$row = $this->refetch($row);
		}

		if ($row['status'] === 'running' && $row['ends_at_ms'] <= $this->nowMs()) {
			if ($this->mapper->finishIfRunning($row['id'], $row['ends_at_ms'], $today)) {
				$this->notifyMembers($row);
			}
			$row = $this->refetch($row);
		}

		return $row;
	}

	private function refetch(array $row): array {
		$fresh = $this->mapper->find($row['id']);
		return $fresh === null ? $row : array_merge($row, $fresh);
	}

	private function requireMember(int $id, string $uid): array {
		$row = $this->mapper->find($id);
		if ($row === null || !$this->mapper->isMember($id, $uid)) {
			throw new NotFoundException('Timer not found');
		}
		return $row;
	}

	private function requireOwner(int $id, string $uid): array {
		$row = $this->requireMember($id, $uid);
		if ($row['owner'] !== $uid) {
			throw new ForbiddenException('Only the owner can do this');
		}
		return $row;
	}

	private function single(array $row, string $uid): array {
		$members = $this->mapper->membersByTimer([$row['id']])[$row['id']] ?? [];
		return [
			'now' => $this->nowMs(),
			'timer' => $this->view($row, $uid, $members),
		];
	}

	/** @param string[] $memberUids */
	private function view(array $row, string $uid, array $memberUids): array {
		$now = $this->nowMs();
		$remaining = $row['status'] === 'running'
			? max(0, $row['ends_at_ms'] - $now)
			: $row['remaining_ms'];

		return [
			'id' => $row['id'],
			'name' => $row['name'],
			'description' => $row['description'],
			'duration' => $row['duration'],
			'resettable' => $row['resettable'],
			'status' => $row['status'],
			'remainingMs' => $remaining,
			'endsAtMs' => $row['ends_at_ms'],
			'finishedAtMs' => $row['finished_at_ms'],
			'startedBy' => $row['started_by'] !== null ? $this->displayName($row['started_by']) : null,
			'isOwner' => $row['owner'] === $uid,
			'members' => array_map(fn (string $m): array => [
				'uid' => $m,
				'displayName' => $this->displayName($m),
				'isOwner' => $m === $row['owner'],
			], $memberUids),
		];
	}

	private function displayName(string $uid): string {
		if (!isset($this->names[$uid])) {
			$this->names[$uid] = $this->userManager->getDisplayName($uid) ?? $uid;
		}
		return $this->names[$uid];
	}

	private function nowMs(): int {
		return (int)floor(microtime(true) * 1000);
	}

	private function today(string $timezone): string {
		try {
			$zone = new \DateTimeZone($timezone);
		} catch (\Throwable) {
			$zone = new \DateTimeZone('UTC');
		}
		return (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
	}

	private function cleanName(string $name): string {
		$name = trim((string)preg_replace('/\s+/u', ' ', $name));
		if ($name === '') {
			throw new \InvalidArgumentException('A name is required');
		}
		return mb_substr($name, 0, 128);
	}

	private function cleanDescription(string $description): string {
		$description = str_replace(["\r\n", "\r"], "\n", trim($description));
		$description = (string)preg_replace("/\n{3,}/", "\n\n", $description);
		return mb_substr($description, 0, 150);
	}

	private function cleanMinutes(int $minutes): int {
		return max(1, min(self::MAX_MINUTES, $minutes));
	}

	private function cleanTimezone(string $timezone): string {
		if ($timezone === '') {
			return 'UTC';
		}
		try {
			new \DateTimeZone($timezone);
			return $timezone;
		} catch (\Throwable) {
			return 'UTC';
		}
	}

	private function notifyMembers(array $row): void {
		$members = $this->mapper->membersByTimer([$row['id']])[$row['id']] ?? [];
		foreach ($members as $uid) {
			try {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime())
					->setObject('timer', (string)$row['id'])
					->setSubject('finished', [
						'name' => $row['name'],
						'minutes' => intdiv($row['duration'], 60),
					]);
				$this->notifications->notify($n);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not send timer notification', [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
	}

	private function clearNotifications(int $timerId): void {
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)->setObject('timer', (string)$timerId);
		$this->notifications->markProcessed($n);
	}
}
