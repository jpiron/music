<?php

declare(strict_types=1);

namespace OCA\Music\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the table for the smart playlist definitions
 */
class Version030300Date20261018120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_smartlists')) {
			$table = $schema->createTable('music_smartlists');
			$table->addColumn('id',               Types::INTEGER,            ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id',          Types::STRING,             ['notnull' => true, 'length' => 64]);
			$table->addColumn('playlist_id',      Types::INTEGER,            ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('criteria',         Types::TEXT,               ['notnull' => false]);
			$table->addColumn('refresh_interval', Types::INTEGER,            ['notnull' => false]);
			$table->addColumn('refreshed',        Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['playlist_id'], 'music_smartlists_pl_idx');
			$table->addIndex(['user_id'], 'music_smartlists_user_idx');
		}

		return $schema;
	}
}
