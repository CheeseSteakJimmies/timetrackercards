<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Service;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;

/**
 * Finds users to invite with Nextcloud's own sharee search, so the admin's
 * sharing settings (autocompletion, group restrictions) are respected.
 */
class UserSearchService {
	public function __construct(
		private ISearch $search,
	) {
	}

	/**
	 * @param string[] $exclude user ids that must not be returned
	 * @return array<int, array{uid: string, displayName: string}>
	 */
	public function search(string $query, array $exclude = [], int $limit = 10): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}

		[$result] = $this->search->search($query, [IShare::TYPE_USER], false, $limit + count($exclude), 0);

		$users = [];
		$seen = [];
		$groups = [$result['exact']['users'] ?? [], $result['users'] ?? []];
		foreach ($groups as $group) {
			foreach ($group as $item) {
				$uid = (string)($item['value']['shareWith'] ?? '');
				if ($uid === '' || isset($seen[$uid]) || in_array($uid, $exclude, true)) {
					continue;
				}
				$seen[$uid] = true;
				$users[] = [
					'uid' => $uid,
					'displayName' => (string)($item['label'] ?? $uid),
				];
				if (count($users) >= $limit) {
					break 2;
				}
			}
		}
		return $users;
	}
}
