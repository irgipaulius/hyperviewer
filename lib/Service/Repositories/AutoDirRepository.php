<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service\Repositories;

use OCP\IDBConnection;

class AutoDirRepository {
	private string $table = '*PREFIX*hyperviewer_auto_dirs';
	private IDBConnection $conn;
	private string $userId;

	public function __construct(IDBConnection $conn, string $userId) {
		$this->conn = $conn;
		$this->userId = $userId;
	}

	public function ensureSchema(): void {
		$sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$this->table} (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	user_id VARCHAR(64) NOT NULL,
	dir_file_id BIGINT NOT NULL,
	dir_path TEXT NOT NULL,
	enabled BOOLEAN NOT NULL DEFAULT 1,
	options_json TEXT DEFAULT '{}',
	cache_size BIGINT DEFAULT 0,
	last_scan_at TIMESTAMP NULL DEFAULT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uniq_dir_user (user_id, dir_file_id)
)
SQL;

		$this->conn->executeStatement($sql);
	}

	public function upsert(
		int $dirFileId,
		string $dirPath,
		array $options,
		int $cacheSize = 0,
		bool $enabled = true
	): void {
		$sql = <<<SQL
INSERT INTO {$this->table}
	(user_id, dir_file_id, dir_path, enabled, options_json, cache_size, last_scan_at, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE
	dir_path = VALUES(dir_path),
	enabled = VALUES(enabled),
	options_json = VALUES(options_json),
	cache_size = VALUES(cache_size),
	updated_at = CURRENT_TIMESTAMP
SQL;

		$this->conn->executeStatement($sql, [
			$this->userId,
			$dirFileId,
			$dirPath,
			$enabled ? 1 : 0,
			json_encode($options),
			$cacheSize
		]);
	}

	/**
	 * @return array[] List of auto-dir rows for this user.
	 */
	public function getAll(): array {
		$sql = "SELECT * FROM {$this->table} WHERE user_id = ?";
		return $this->conn->fetchAllAssociative($sql, [$this->userId]);
	}

	public function deleteOne(int $dirFileId): void {
		$sql = "DELETE FROM {$this->table} WHERE user_id=? AND dir_file_id=?";
		$this->conn->executeStatement($sql, [$this->userId, $dirFileId]);
	}

}
