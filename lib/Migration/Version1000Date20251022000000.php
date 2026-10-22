<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20251022000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('hv_media')) {
			$table = $schema->createTable('hv_media');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('duration', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('fps', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('width', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('height', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('has_audio', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('cache_state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']);
			$table->addColumn('renditions', Types::TEXT, ['notnull' => false]);
			$table->addColumn('cache_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('pinned', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('auto_disabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_access_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['fingerprint'], 'hv_media_fp');
			$table->addIndex(['cache_state'], 'hv_media_state');
		}

		if (!$schema->hasTable('hv_locations')) {
			$table = $schema->createTable('hv_locations');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('media_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('fileid', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('storage_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('path_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('seen_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['fileid'], 'hv_loc_fileid');
			$table->addIndex(['media_id'], 'hv_loc_media');
			$table->addIndex(['user_id'], 'hv_loc_user');
			$table->addIndex(['storage_id', 'path_hash'], 'hv_loc_storage_path');
		}

		if (!$schema->hasTable('hv_jobs')) {
			$table = $schema->createTable('hv_jobs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('fileid', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('media_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'queued']);
			$table->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 100]);
			$table->addColumn('progress', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('max_attempts', Types::INTEGER, ['notnull' => true, 'default' => 3]);
			$table->addColumn('next_attempt_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('worker_pid', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('heartbeat_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('started_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('finished_at', Types::INTEGER, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['state', 'priority', 'next_attempt_at'], 'hv_jobs_claim');
			$table->addIndex(['media_id'], 'hv_jobs_media');
			$table->addIndex(['user_id', 'state'], 'hv_jobs_user_state');
			$table->addIndex(['type', 'state'], 'hv_jobs_type_state');
		}

		if (!$schema->hasTable('hv_watch_dirs')) {
			$table = $schema->createTable('hv_watch_dirs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('storage_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('resolutions', Types::TEXT, ['notnull' => false]);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 100]);
			$table->addColumn('last_scan_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'enabled'], 'hv_watch_user');
		}

		if (!$schema->hasTable('hv_log')) {
			$table = $schema->createTable('hv_log');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('message', Types::TEXT, ['notnull' => true]);
			$table->addColumn('context', Types::TEXT, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('fileid', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('job_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['created_at'], 'hv_log_created');
			$table->addIndex(['level', 'channel'], 'hv_log_level');
		}

		if (!$schema->hasTable('hv_settings_override')) {
			$table = $schema->createTable('hv_settings_override');
			$table->addColumn('key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('value', Types::TEXT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['key']);
		}

		return $schema;
	}
}
