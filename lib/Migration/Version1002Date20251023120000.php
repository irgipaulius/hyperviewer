<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1002Date20251023120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('hv_public_links')) {
			$table = $schema->createTable('hv_public_links');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('fileid', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('media_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('expires_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('view_count', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('revoked_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'hv_pl_token');
			$table->addIndex(['user_id'], 'hv_pl_user');
			$table->addIndex(['fileid'], 'hv_pl_fileid');
		}
		return $schema;
	}
}
