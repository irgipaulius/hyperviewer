<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;

class FFmpegProcessManager {

	private IConfig $config;
	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private LoggerInterface $logger;
	private ITimeFactory $timeFactory;
	private HlsService $hlsService;

	private \OCP\Files\IAppData $appData;
	private int $maxConcurrentJobs = 2;
	private int $maxAttempts = 3;

	public function __construct(
		IConfig $config,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		LoggerInterface $logger,
		ITimeFactory $timeFactory,
		HlsService $hlsService
	) {
		$this->config = $config;
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->timeFactory = $timeFactory;
		$this->hlsService = $hlsService;

		// Setup AppData
		$this->appData = \OC::$server->getAppDataDir('hyperviewer');
	}

	/**
	 * Add a job to the queue
	 */
	public function addJob(string $userId, string $filename, string $directory, array $settings): string {
		$queue = $this->readQueue();
		
		$jobId = uniqid('hls_', true);
		$job = [
			'id' => $jobId,
			'userId' => $userId,
			'filename' => $filename,
			'directory' => $directory,
			'settings' => $settings,
			'status' => 'pending',
			'addedAt' => time(),
			'attempts' => 0
		];

		// Check for duplicates
		foreach ($queue as $existingJob) {
			if ($existingJob['userId'] === $userId && 
				$existingJob['filename'] === $filename && 
				$existingJob['directory'] === $directory &&
				$existingJob['status'] !== 'failed') {
				return $existingJob['id'];
			}
		}

		$queue[] = $job;
		$this->saveQueue($queue);
		
		return $jobId;
	}

	/**
	 * Check if a job is already queued (pending, processing, or failed with retries remaining)
	 */
	public function isJobQueued(string $userId, string $filename, string $directory): bool {
		$queue = $this->readQueue();
		
		foreach ($queue as $job) {
			if ($job['userId'] === $userId && 
				$job['filename'] === $filename && 
				$job['directory'] === $directory &&
				$job['status'] !== 'aborted') {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Get the current queue (for batch checking)
	 */
	public function getQueue(): array {
		return $this->readQueue();
	}

	/**
	 * Filter out videos that are already in queue (except aborted jobs)
	 * Returns only videos that need to be queued
	 */
	public function filterNotQueued(array $videos, string $userId): array {
		$queue = $this->readQueue();
		
		return array_filter($videos, function($video) use ($queue, $userId) {
			foreach ($queue as $job) {
				if ($job['userId'] === $userId && 
					$job['filename'] === $video['filename'] && 
					$job['directory'] === $video['directory'] &&
					$job['status'] !== 'aborted') {
					return false; // Already queued, skip
				}
			}
			return true; // Not queued, keep
		});
	}

	/**
	 * Process the queue
	 */
	public function processQueue(): void {
		$queue = $this->readQueue();
		$activeJobs = 0;
		$pendingJobs = [];

		// Count active jobs and filter pending/failed jobs that can be retried
		foreach ($queue as $job) {
			if ($job['status'] === 'processing') {
				// Check if process is actually still running
				if ($this->isJobRunning($job)) {
					$activeJobs++;
				} else {
					// Job crashed or finished without updating status
					$this->handleStaleJob($job['id']);
				}
			} elseif ($job['status'] === 'pending') {
				$pendingJobs[] = $job;
			} elseif ($job['status'] === 'failed') {
				// Failed jobs can be retried if they haven't reached max attempts
				$attempts = $job['attempts'] ?? 0;
				if ($attempts < $this->maxAttempts) {
					$pendingJobs[] = $job;
				}
			}
		}

		// Start new jobs if slots available
		if ($activeJobs < $this->maxConcurrentJobs && !empty($pendingJobs)) {
			$slotsAvailable = $this->maxConcurrentJobs - $activeJobs;
			$jobsToStart = array_slice($pendingJobs, 0, $slotsAvailable);

			foreach ($jobsToStart as $job) {
				$this->startJob($job['id']);
			}
		}

		$this->logger->error('Active jobs: ' . $activeJobs . ' Pending jobs: ' . count($pendingJobs) . ' ');
	}

	/**
	 * Start a specific job
	 */
	private function startJob(string $jobId): void {
		$queue = $this->readQueue();
		$jobIndex = -1;
		
		foreach ($queue as $index => $job) {
			if ($job['id'] === $jobId) {
				$jobIndex = $index;
				break;
			}
		}

		if ($jobIndex === -1) return;

		// Update status to processing and increment attempts
		$queue[$jobIndex]['status'] = 'processing';
		$queue[$jobIndex]['startedAt'] = time();
		$queue[$jobIndex]['pid'] = getmypid();
		$queue[$jobIndex]['attempts'] = ($queue[$jobIndex]['attempts'] ?? 0) + 1;
		$this->saveQueue($queue);

		try {
			$this->logger->error('running transcoding job: ' . $queue[$jobIndex]['filename']);
			// Delegate execution to HlsService
			$this->hlsService->transcode(
				$queue[$jobIndex]['userId'],
				$queue[$jobIndex]['filename'],
				$queue[$jobIndex]['directory'],
				$queue[$jobIndex]['settings']
			);
			
			// If we get here, transcoding finished successfully
			$this->updateJobStatus($jobId, 'completed');
		} catch (\Exception $e) {	
			$this->logger->error('Transcoding failed: ' . $e->getMessage() . ' Job: ' . json_encode($queue[$jobIndex]));
			$this->updateJobStatus($jobId, 'failed', $e->getMessage());
		}
	}

	/**
	 * Helper to read queue
	 */
	private function readQueue(): array {
		try {
			$folder = $this->appData->getFolder('/');
			$file = $folder->getFile('queue.json');
			$content = $file->getContent();
			return json_decode($content, true) ?: [];
		} catch (\OCP\Files\NotFoundException $e) {
			return [];
		} catch (\Exception $e) {
			$this->logger->error('Failed to read queue: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Helper to save queue
	 */
	private function saveQueue(array $queue): void {
		try {
			try {
				$folder = $this->appData->getFolder('/');
			} catch (\OCP\Files\NotFoundException $e) {
				$folder = $this->appData->newFolder('/');
			}

			try {
				$file = $folder->getFile('queue.json');
			} catch (\OCP\Files\NotFoundException $e) {
				$file = $folder->newFile('queue.json');
			}
			
			$file->putContent(json_encode($queue, JSON_PRETTY_PRINT));
		} catch (\Exception $e) {
			$this->logger->error('Failed to save queue: ' . $e->getMessage());
		}
	}

	/**
	 * Set queue (public method for external updates)
	 */
	public function setQueue(array $queue): void {
		$this->saveQueue($queue);
	}

	/**
	 * Update job status
	 */
	private function updateJobStatus(string $jobId, string $status, string $error = null): void {
		$queue = $this->readQueue();
		$jobIndex = -1;
		
		foreach ($queue as $index => $job) {
			if ($job['id'] === $jobId) {
				$jobIndex = $index;
				break;
			}
		}
		
		if ($jobIndex === -1) {
			$this->saveQueue($queue);
			return;
		}
		
		$job = &$queue[$jobIndex];
		$job['status'] = $status;
		
		if ($status === 'completed') {
			$job['completedAt'] = time();
			unset($job['error']); // Clear any previous errors
		} elseif ($status === 'failed') {
			$job['failedAt'] = time();
			$attempts = $job['attempts'] ?? 0;
			
			if ($error) {
				$job['error'] = $error;
			}
			
			// Check if job can be retried
			if ($attempts < $this->maxAttempts) {
				// Keep status as 'failed', move to end of queue for retry
				$failedJob = $queue[$jobIndex];
				$failedJob['status'] = 'failed'; // Keep as failed
				
				// Remove from current position
				array_splice($queue, $jobIndex, 1);
				
				// Add to end of queue
				$queue[] = $failedJob;
				
				$this->logger->info("Job {$jobId} failed (attempt {$attempts}/{$this->maxAttempts}), moved to end of queue for retry");
			} else {
				// Max attempts reached - mark as aborted (keep in queue for history)
				$job['status'] = 'aborted';
				$job['abortedAt'] = time();
				$this->logger->error("Job {$jobId} permanently failed after {$attempts} attempts, marked as aborted: {$error}");
			}
		}
		
		$this->saveQueue($queue);
	}

	private function isJobRunning(array $job): bool {
		// Simple check: if started more than 8 hours ago, assume dead
		if (isset($job['startedAt']) && (time() - $job['startedAt']) > 4*7200) {
			return false;
		}
		return true;
	}

	private function handleStaleJob(string $jobId): void {
		$this->updateJobStatus($jobId, 'failed', 'Job timed out or crashed');
	}
	
	public function getActiveJobs(): array {
		$queue = $this->readQueue();
		return $queue;
	}

	public function getJobStatistics(): array {
		$queue = $this->readQueue();
		$stats = [
			'active' => 0,
			'pending' => 0,
			'completed' => 0,
			'failed' => 0,
			'total' => count($queue)
		];

		foreach ($queue as $job) {
			$status = $job['status'] ?? 'unknown';
			if (isset($stats[$status])) {
				$stats[$status]++;
			}
		}

		return $stats;
	}

	/**
	 * Get a specific job by ID
	 */
	public function getJob(string $jobId): ?array {
		$queue = $this->readQueue();
		foreach ($queue as $job) {
			if ($job['id'] === $jobId) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Delete a specific job
	 */
	public function deleteJob(string $jobId, string $userId): bool {
		$queue = $this->readQueue();
		$originalCount = count($queue);
		
		$queue = array_filter($queue, function($job) use ($jobId, $userId) {
			// Keep job if ID doesn't match OR userId doesn't match (security check)
			return !($job['id'] === $jobId && $job['userId'] === $userId);
		});

		if (count($queue) !== $originalCount) {
			$this->saveQueue(array_values($queue));
			return true;
		}
		
		return false;
	}
}
