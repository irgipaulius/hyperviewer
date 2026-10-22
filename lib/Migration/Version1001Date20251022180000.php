<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Location identity is storage_id + path_hash (fileid is an optional hint).
 */
class Version1001Date20251022180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('hv_locations')) {
			return $schema;
		}
		$table = $schema->getTable('hv_locations');
		if ($table->hasIndex('hv_loc_fileid')) {
			$table->dropIndex('hv_loc_fileid');
		}
		if (!$table->hasIndex('hv_loc_fileid_hint')) {
			$table->addIndex(['fileid'], 'hv_loc_fileid_hint');
		}
		if ($table->hasIndex('hv_loc_storage_path')) {
			$table->dropIndex('hv_loc_storage_path');
		}
		$table->addUniqueIndex(['storage_id', 'path_hash'], 'hv_loc_storage_path');
		if ($table->getColumn('fileid')->getNotnull()) {
			$column = $table->getColumn('fileid');
			$column->setNotnull(false);
			$column->setDefault(null);
		}
		return $schema;
	}
}
