<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Plain query-builder access to the two tables:
 *  - timetrackercards_timers: one row per timer card
 *  - timetrackercards_members: who can see/use a timer (the owner is a member too),
 *    and in which position each member has put the card on their own board
 */
class TimerMapper {
	private const TIMERS = 'timetrackercards_timers';
	private const MEMBERS = 'timetrackercards_members';
	private const INT_COLUMNS = ['duration', 'resettable', 'remaining_ms', 'ends_at_ms', 'finished_at_ms', 'created_at'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function find(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TIMERS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->cast($row);
	}

	/** @return array[] timers of a user in the order that user has chosen */
	public function findForUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*')
			->addSelect('m.position')
			->from(self::TIMERS, 't')
			->innerJoin('t', self::MEMBERS, 'm', $qb->expr()->eq('t.id', 'm.timer_id'))
			->where($qb->expr()->eq('m.uid', $qb->createNamedParameter($uid)))
			->orderBy('m.position', 'ASC')
			->addOrderBy('t.id', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $this->cast($row);
		}
		$result->closeCursor();
		return $rows;
	}

	/** @return array[] running timers whose time has run out */
	public function findExpired(int $nowMs): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TIMERS)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('running')))
			->andWhere($qb->expr()->lte('ends_at_ms', $qb->createNamedParameter($nowMs, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $this->cast($row);
		}
		$result->closeCursor();
		return $rows;
	}

	public function insert(array $values): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TIMERS);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $this->param($qb, $column, $value));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	public function update(int $id, array $fields): void {
		if ($fields === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TIMERS);
		foreach ($fields as $column => $value) {
			$qb->set($column, $this->param($qb, $column, $value));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Atomically moves a running timer to "finished". Returns true for exactly
	 * one caller, so the notification is only sent once even if several
	 * requests notice the end at the same moment.
	 */
	public function finishIfRunning(int $id, int $endsAtMs, string $day): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TIMERS)
			->set('status', $qb->createNamedParameter('finished'))
			->set('remaining_ms', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('ends_at_ms', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('finished_at_ms', $qb->createNamedParameter($endsAtMs, IQueryBuilder::PARAM_INT))
			->set('reset_day', $qb->createNamedParameter($day))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('running')))
			->andWhere($qb->expr()->eq('ends_at_ms', $qb->createNamedParameter($endsAtMs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}

	public function countOwned(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from(self::TIMERS)
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();
		return (int)$count;
	}

	/** @return int[] */
	public function ownedIds(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TIMERS)
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	public function deleteTimer(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::MEMBERS)
			->where($qb->expr()->eq('timer_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TIMERS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param int[] $ids @return array<int, string[]> member uids per timer id, owner first */
	public function membersByTimer(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('timer_id', 'uid')
			->from(self::MEMBERS)
			->where($qb->expr()->in('timer_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$members = [];
		while ($row = $result->fetch()) {
			$members[(int)$row['timer_id']][] = (string)$row['uid'];
		}
		$result->closeCursor();
		return $members;
	}

	public function isMember(int $timerId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::MEMBERS)
			->where($qb->expr()->eq('timer_id', $qb->createNamedParameter($timerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetch() !== false;
		$result->closeCursor();
		return $found;
	}

	public function addMember(int $timerId, string $uid, int $position): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::MEMBERS)
			->setValue('timer_id', $qb->createNamedParameter($timerId, IQueryBuilder::PARAM_INT))
			->setValue('uid', $qb->createNamedParameter($uid))
			->setValue('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->executeStatement();
	}

	public function removeMember(int $timerId, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::MEMBERS)
			->where($qb->expr()->eq('timer_id', $qb->createNamedParameter($timerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	public function removeUserEverywhere(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::MEMBERS)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	public function nextPosition(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('position'))
			->from(self::MEMBERS)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return ($max === null || $max === false) ? 0 : ((int)$max + 1);
	}

	public function setPosition(int $timerId, string $uid, int $position): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::MEMBERS)
			->set('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('timer_id', $qb->createNamedParameter($timerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	private function param(IQueryBuilder $qb, string $column, mixed $value): mixed {
		if ($value === null) {
			return $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL);
		}
		if (is_bool($value)) {
			$value = $value ? 1 : 0;
		}
		$type = in_array($column, self::INT_COLUMNS, true) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
		return $qb->createNamedParameter($value, $type);
	}

	private function cast(array $r): array {
		$row = [
			'id' => (int)$r['id'],
			'owner' => (string)$r['owner'],
			'name' => (string)$r['name'],
			'description' => (string)($r['description'] ?? ''),
			'duration' => (int)$r['duration'],
			'resettable' => ((int)$r['resettable']) === 1,
			'timezone' => (string)$r['timezone'],
			'status' => (string)$r['status'],
			'remaining_ms' => (int)$r['remaining_ms'],
			'ends_at_ms' => (int)$r['ends_at_ms'],
			'finished_at_ms' => (int)$r['finished_at_ms'],
			'started_by' => $r['started_by'] === null ? null : (string)$r['started_by'],
			'reset_day' => (string)($r['reset_day'] ?? ''),
			'created_at' => (int)$r['created_at'],
		];
		if (array_key_exists('position', $r)) {
			$row['position'] = (int)$r['position'];
		}
		return $row;
	}
}
