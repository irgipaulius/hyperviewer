<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service\Repositories;

use OCP\IDBConnection;

class QueueRepository {
	public const STATUS_QUEUED = 'queued';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';
	public const PRIORITY_REQUEUE_FAILED = 10;
	
	private string $table = "*PREFIX*hyperviewer_queue";
	private IDBConnection $conn;
	private ?string $worker;

	public function __construct(IDBConnection $conn, ?string $worker) {
		$this->conn = $conn;
		$this->worker = $worker;
	}

	public function ensureSchema(): void {
		$sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$this->table} (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	file_id BIGINT NOT NULL,
	user_id VARCHAR(64) NOT NULL,
	file_path TEXT NOT NULL,
	cache_path TEXT NOT NULL,
	job_data TEXT NOT NULL DEFAULT '{}',
	status VARCHAR(16) NOT NULL DEFAULT 'queued',
	error TEXT,
	priority INTEGER NOT NULL DEFAULT 5,
	retry_count INTEGER NOT NULL DEFAULT 0,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	started_at TIMESTAMP NULL DEFAULT NULL,
	finished_at TIMESTAMP NULL DEFAULT NULL,
	lock_token VARCHAR(64) NULL DEFAULT NULL,
	worker VARCHAR(64) NULL DEFAULT NULL,
	INDEX idx_status_priority (status, priority, created_at),
	INDEX idx_user (user_id)
)
SQL;

		// SQLite uses AUTOINCREMENT, pgsql/mysql ignore keyword gracefully
		$this->conn->executeStatement($sql);

		try {
			$this->conn->executeStatement("ALTER TABLE {$this->table} ADD COLUMN job_data TEXT NOT NULL DEFAULT '{}' ");
		} catch (\Throwable $e) {
		}
	}

	/**
	 * @param int $priority lower is higher priority
	 */
	/**
	 * $jobData format:
	 *  - overwriteExisting: bool
	 *  - resolutions: string[] (e.g. ['720p','480p','360p','240p'])
	 */
	public function enqueue(
		int $fileId,
		string $filePath,
		string $cachePath,
		string $userId,
		array $jobData = [],
		int $priority = 5
	): void {
		$sql = <<<SQL
INSERT INTO {$this->table}
	(file_id, user_id, file_path, cache_path, job_data, status, priority, created_at, updated_at, lock_token, worker)
VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NULL, ?)
SQL;
		$this->conn->executeStatement($sql, [
			$fileId,
			$userId,
			$filePath,
			$cachePath,
			json_encode($jobData),
			$this->STATUS_QUEUED,
			$priority,
			$this->worker
		]);
	}

	/**
	 * Atomically take the next queued job and mark it processing.
	 * MySQL-compatible: claim via lock token to avoid RETURNING/transactions.
	 * @return array|null Updated job row or null if none.
	 */
	public function fetchNextQueued(): ?array {
		$now = date('Y-m-d H:i:s');
		$token = bin2hex(random_bytes(16));

		$updated = $this->conn->executeStatement(
			"UPDATE {$this->table}
			 SET status = ?, started_at = ?, updated_at = ?, lock_token = ?, worker = ?
			 WHERE status = ?
			 ORDER BY priority ASC, created_at ASC
			 LIMIT 1",
			[$this->STATUS_PROCESSING, $now, $now, $token, $this->worker, $this->STATUS_QUEUED]
		);

		if ($updated === 0) {
			return null;
		}

		$row = $this->conn->fetchAssociative("SELECT * FROM {$this->table} WHERE lock_token = ?", [$token]);
		return $row ?: null;
	}

	public function getOne(int $jobId): ?array {
		$row = $this->conn->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$jobId]);
		return $row ?: null;
	}

	public function countProcessing(): int {
		$row = $this->conn->fetchAssociative(
			"SELECT COUNT(*) AS cnt FROM {$this->table} WHERE status = ?",
			[self::STATUS_PROCESSING]
		);
		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * Mark job done (no row returned).
	 */
	public function markDone(int $jobId): void {
		$now = date('Y-m-d H:i:s');
		$sql = "UPDATE {$this->table} SET status=?, finished_at=?, updated_at=? WHERE id=?";
		$this->conn->executeStatement($sql, [$this->STATUS_DONE, $now, $now, $jobId]);
	}

	/**
	 * Mark job failed or requeue it. Returns nothing.
	 */
	public function markFailed(int $jobId, string $error, bool $requeue = false): void {
		$now = date('Y-m-d H:i:s');
		if ($requeue) {
			$sql = "UPDATE {$this->table} SET status=?, error=?, retry_count=retry_count+1, updated_at=?, priority=?, lock_token=NULL WHERE id=?";
			$this->conn->executeStatement($sql, [$this->STATUS_QUEUED, $error, $now, self::PRIORITY_REQUEUE_FAILED, $jobId]);
		} else {
			$sql = "UPDATE {$this->table} SET status=?, error=?, finished_at=?, updated_at=?, lock_token=NULL WHERE id=?";
			$this->conn->executeStatement($sql, [$this->STATUS_FAILED, $error, $now, $now, $jobId]);
		}
	}

	/**
	 * Get paginated jobs for the current user.
	 * @param int $page 1-based page index
	 * @param int $pageSize number of records per page
	 * @return array[]
	 */
	public function listForUser(string $userId, int $page = 1, int $pageSize = 100): array {
		$page = max(1, $page);
		$pageSize = max(1, $pageSize);
		$offset = ($page - 1) * $pageSize;

		$sql = "SELECT * FROM {$this->table} WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
		return $this->conn->fetchAllAssociative($sql, [$userId, $pageSize, $offset]);
	}
}
