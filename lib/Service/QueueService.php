<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCP\BackgroundJob\IJobList;

class QueueService {
	public const PRIORITY_CLIP = 10;
	public const PRIORITY_ENCODE_FIRST = 30;
	public const PRIORITY_ENCODE_UPGRADE = 50;
	public const PRIORITY_FINGERPRINT = 70;
	public const PRIORITY_SCAN = 90;

	public function __construct(
		private JobMapper $jobMapper,
		private LogMapper $logMapper,
		private IJobList $jobList,
		private ConfigService $config,
	) {
	}

	public function enqueue(Job $job): Job {
		if ($this->config->getBool(ConfigService::KEY_QUEUE_PAUSED)) {
			throw new \RuntimeException('Queue is paused');
		}
		$job->setState('queued');
		$job->setCreatedAt(time());
		$job->setNextAttemptAt(time());
		$inserted = $this->jobMapper->insert($job);
		$this->ensureWakeJob();
		$this->logMapper->append('info', 'queue', 'Job enqueued', ['type' => $job->getType(), 'id' => $inserted->getId()], $job->getUserId(), $job->getFileid(), $inserted->getId());
		return $inserted;
	}

	public function enqueueEncode(string $userId, int $fileid, int $mediaId, array $resolutions, bool $firstRenditionOnly): Job {
		$type = $firstRenditionOnly ? 'encode' : 'upgrade';
		if ($this->jobMapper->hasActiveForMedia($type === 'encode' ? 'encode' : 'upgrade', $mediaId)) {
			throw new \RuntimeException('Encode already queued for media');
		}
		$job = new Job();
		$job->setType($type);
		$job->setFileid($fileid);
		$job->setMediaId($mediaId);
		$job->setUserId($userId);
		$job->setPayload(json_encode(['resolutions' => $resolutions]));
		$job->setPriority($firstRenditionOnly ? self::PRIORITY_ENCODE_FIRST : self::PRIORITY_ENCODE_UPGRADE);
		return $this->enqueue($job);
	}

	public function enqueueFingerprint(string $userId, int $fileid, ?array $payload = null): Job {
		$job = new Job();
		$job->setType('fingerprint');
		$job->setFileid($fileid);
		$job->setUserId($userId);
		$job->setPriority(self::PRIORITY_FINGERPRINT);
		if ($payload !== null) {
			$job->setPayload(json_encode($payload));
		}
		return $this->enqueue($job);
	}

	public function enqueueClip(string $userId, int $fileid, array $payload): Job {
		$job = new Job();
		$job->setType('clip');
		$job->setFileid($fileid);
		$job->setUserId($userId);
		$job->setPayload(json_encode($payload));
		$job->setPriority(self::PRIORITY_CLIP);
		return $this->enqueue($job);
	}

	public function cancel(int $jobId): void {
		$job = $this->jobMapper->findById($jobId);
		$job->setState('cancelled');
		$job->setFinishedAt(time());
		$this->jobMapper->update($job);
	}

	public function retry(int $jobId): Job {
		$job = $this->jobMapper->findById($jobId);
		$job->setState('queued');
		$job->setAttempts(0);
		$job->setError(null);
		$job->setNextAttemptAt(time());
		$job->setFinishedAt(null);
		$this->jobMapper->update($job);
		$this->ensureWakeJob();
		return $job;
	}

	public function bumpPriority(int $jobId): Job {
		$job = $this->jobMapper->findById($jobId);
		if ($job->getState() !== 'queued') {
			throw new \RuntimeException('Only queued jobs can be bumped');
		}
		$job->setPriority(max(0, $job->getPriority() - 50));
		$this->jobMapper->update($job);
		$this->ensureWakeJob();
		return $job;
	}

	public function markFailed(Job $job, string $error): void {
		$job->setAttempts($job->getAttempts() + 1);
		$job->setError(mb_substr($error, 0, 2000));
		if ($job->getAttempts() >= $job->getMaxAttempts()) {
			$job->setState('failed');
			$job->setFinishedAt(time());
		} else {
			$backoff = [60, 600, 3600];
			$idx = min($job->getAttempts() - 1, count($backoff) - 1);
			$job->setState('queued');
			$job->setNextAttemptAt(time() + $backoff[$idx]);
		}
		$job->setWorkerPid(null);
		$this->jobMapper->update($job);
		if ($job->getState() === 'queued') {
			$this->ensureWakeJob();
		}
	}

	public function markDone(Job $job): void {
		$job->setState('done');
		$job->setProgress(100);
		$job->setFinishedAt(time());
		$job->setWorkerPid(null);
		$this->jobMapper->update($job);
	}

	public function heartbeat(Job $job): void {
		$job->setHeartbeatAt(time());
		$this->jobMapper->update($job);
	}

	public function ensureWakeJob(): void {
		if (!$this->jobList->has(\OCA\HyperViewer\BackgroundJob\ProcessHvQueueJob::class, null)) {
			$this->jobList->add(\OCA\HyperViewer\BackgroundJob\ProcessHvQueueJob::class);
		}
	}
}
