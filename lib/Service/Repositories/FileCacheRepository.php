<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service\Repositories;

use OCP\IDBConnection;

class FileCacheRepository {
	private string $table = '*PREFIX*hyperviewer_file_cache';
	private IDBConnection $conn;

	public function __construct(IDBConnection $conn) {
		$this->conn = $conn;
	}

	public function ensureSchema(): void {
		$sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$this->table} (
	file_id BIGINT PRIMARY KEY,
	file_path TEXT NOT NULL,
	cache_path TEXT NULL,
	cache_size BIGINT NULL,
	original_content_length BIGINT DEFAULT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	INDEX idx_file_id (file_id)
)
SQL;

		$this->conn->executeStatement($sql);
	}

	public function upsert(
		int $fileId,
		string $filePath,
		?string $cachePath,
		?int $cacheSize,
		?int $originalContentLength
	): void {
		$sql = <<<SQL
INSERT INTO {$this->table}
	(file_id, file_path, cache_path, cache_size, original_content_length, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, NOW(), NOW())
ON DUPLICATE KEY UPDATE
	file_path = VALUES(file_path),
	cache_path = VALUES(cache_path),
	cache_size = VALUES(cache_size),
	original_content_length = VALUES(original_content_length),
	updated_at = CURRENT_TIMESTAMP
SQL;

		$this->conn->executeStatement($sql, [
			$fileId,
			$filePath,
			$cachePath,
			$cacheSize,
			$originalContentLength
		]);
	}

	/** efficiently verifies that cache exists for the fileIds.
	 * @return array of fileIds that have caches
	 */
	public function hasCaches(array $fileIds): array {
		if (empty($fileIds)) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($fileIds), '?'));
		$sql = "SELECT file_id FROM {$this->table} WHERE file_id IN ($placeholders) AND cache_path IS NOT NULL";
		return array_map('intval', $this->conn->fetchFirstColumn($sql, $fileIds));
	}

	/**
	 * @return array|null Cache row for given file_id, or null if missing.
	 */
	public function getOne(int $fileId): ?array {
		$sql = "SELECT * FROM {$this->table} WHERE file_id = ?";
		$row = $this->conn->fetchAssociative($sql, [$fileId]);
		return $row ?: null;
	}

	public function getCachePath(int $fileId): ?string {
		$sql = "SELECT cache_path FROM {$this->table} WHERE file_id = ?";
		$row = $this->conn->fetchAssociative($sql, [$fileId]);
		return $row['cache_path'] ?? null;
	}

}

