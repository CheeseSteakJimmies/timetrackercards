<?php

declare(strict_types=1);

namespace OCA\TimeTrackerCards\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261026120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('timetrackercards_timers')) {
			$table = $schema->createTable('timetrackercards_timers');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('duration', Types::INTEGER, ['notnull' => true, 'default' => 2700]);
			$table->addColumn('resettable', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('timezone', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'UTC']);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'idle']);
			$table->addColumn('remaining_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('ends_at_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('finished_at_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('reset_day', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status', 'ends_at_ms'], 'ttc_timers_run');
			$table->addIndex(['owner'], 'ttc_timers_owner');
		}

		if (!$schema->hasTable('timetrackercards_members')) {
			$table = $schema->createTable('timetrackercards_members');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('timer_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['timer_id', 'uid'], 'ttc_members_uniq');
			$table->addIndex(['uid'], 'ttc_members_uid');
		}

		return $schema;
	}
}
