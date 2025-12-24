<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Service\DbController;
use OCA\HyperViewer\Service\CacheLibrary;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class QueueManager {
    private IDBConnection $conn;
    private DbController $db;
    private string $workerName;
    private LoggerInterface $logger;
	private CacheLibrary $cacheLibrary;
	private int $maxConcurrentJobs = 4;
	private int $maxAttempts = 3;

	public function __construct(
	) {
        $this->logger = \OC::$server->getLogger();
		$this->conn = \OC::$server->getDatabaseConnection();
		$this->workerName = $this->buildWorkerName();
		$this->db = new DbController(null, $this->workerName);
		$this->cacheLibrary = new CacheLibrary($this->db->cache, $this->conn);
	}

    public function processQueue(): int {
		$active = $this->db->queue->countProcessing();
		$slots = $this->maxConcurrentJobs - $active;
		if ($slots <= 0) {
			return 0;
		}

		$spawned = 0;
		for ($i = 0; $i < $slots; $i++) {
			$job = $this->db->queue->fetchNextQueued();
			if ($job === null) {
				break;
			}

			$jobId = (int)($job['id'] ?? 0);
			if ($jobId <= 0) {
				break;
			}

			if (!$this->startJob($jobId, $this->workerName)) {
				$this->finalizeJob($jobId, $job, new \RuntimeException('Failed to spawn worker'));
				continue;
			}

			$spawned++;
		}

		return $spawned;
    }

	public function finalizeJob(int $jobId, ?array $jobRow, ?\Throwable $error): void {
		if ($jobRow === null) {
			$jobRow = $this->db->queue->getOne($jobId);
		}

		$retryCount = (int)($jobRow['retry_count'] ?? 0);
		if ($error === null) {
			$fileId = (int)($jobRow['file_id'] ?? 0);
			$filePath = (string)($jobRow['file_path'] ?? '');
			$cachePath = (string)($jobRow['cache_path'] ?? '');
			$this->cacheLibrary->registerCache($fileId, $filePath, $cachePath);
			$this->db->queue->markDone($jobId);
			return;
		}

		$this->db->queue->markFailed(
			$jobId,
			$error->getMessage(),
			$retryCount <= $this->maxAttempts
		);
	}

    public function startJob(int $jobId, string $workerName): bool {
		$serverRoot = \OC::$SERVERROOT;
		$occPath = $serverRoot . '/occ';
		$phpBinary = $this->findPhpBinary();
		if ($phpBinary === null) {
			return false;
		}

		$cmd = escapeshellcmd($phpBinary)
			. ' ' . escapeshellarg($occPath)
			. ' hyperviewer:run-job ' . escapeshellarg($jobId)
			. ' > /dev/null 2>&1 &';

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = @proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($process)) {
			return false;
		}

		foreach ($pipes as $pipe) {
			@fclose($pipe);
		}
		@proc_close($process);
		return true;
    }

	private function buildWorkerName(): string {
		$host = gethostname() ?: 'unknown-host';
		$pid = getmypid() ?: 0;
		return $host . ':' . (string)$pid;
	}

	private function findPhpBinary(): ?string {
		$phpCandidates = [
			PHP_BINDIR . '/php',
			'/usr/local/bin/php',
			'/usr/bin/php',
			PHP_BINARY,
		];
		foreach ($phpCandidates as $candidate) {
			if ($candidate && @is_executable($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

}