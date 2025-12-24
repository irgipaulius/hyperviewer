<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\IDBConnection;
use OCA\HyperViewer\Service\Repositories\AutoDirRepository;
use OCA\HyperViewer\Service\Repositories\QueueRepository;
use OCA\HyperViewer\Service\Repositories\FileCacheRepository;

class DbController {
	private IDBConnection $conn;

	public AutoDirRepository $dirgen;
	public QueueRepository $queue;
	public FileCacheRepository $cache;

	public function __construct(IDBConnection $conn, ?string $userId, ?string $worker = null) {
		$this->conn = $conn;

		$this->dirgen = new AutoDirRepository($this->conn, $userId);
		$this->queue = new QueueRepository($this->conn, $userId, $worker);
		$this->cache = new FileCacheRepository($this->conn);

		// Ensure tables/columns exist (idempotent, no drops)
		$this->ensureSchema();
	}

	public function ensureSchema(): void {
		$this->dirgen->ensureSchema();
		$this->queue->ensureSchema();
		$this->cache->ensureSchema();
	}
}
