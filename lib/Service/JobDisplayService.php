<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LocationMapper;

class JobDisplayService {
	public function __construct(
		private JobMapper $jobMapper,
		private LocationMapper $locationMapper,
	) {
	}

	/** @return array<string, mixed> */
	public function serialize(Job $job): array {
		$fileid = (int)($job->getFileid() ?? 0);
		$filename = '';
		$directory = '/';
		$filesDir = '/';

		if ($fileid > 0) {
			$loc = $this->locationMapper->findByFileid($fileid);
			if ($loc !== null) {
				$path = $loc->getPath();
				$filename = basename($path);
				$directory = dirname($path);
				if ($directory === '.' || $directory === '\\') {
					$directory = '/';
				}
				$filesDir = $directory;
			}
		}

		$started = $job->getStartedAt();
		$finished = $job->getFinishedAt();
		$duration = null;
		if ($started !== null && $started > 0) {
			$end = ($finished !== null && $finished > 0) ? $finished : time();
			$duration = max(0, $end - $started);
		}

		$chain = $this->buildChainHint($job);

		return [
			'id' => $job->getId(),
			'type' => $job->getType(),
			'type_label' => $this->typeLabel($job->getType()),
			'fileid' => $fileid > 0 ? $fileid : null,
			'filename' => $filename,
			'directory' => $directory,
			'files_dir' => $filesDir,
			'media_id' => $job->getMediaId(),
			'user_id' => $job->getUserId(),
			'state' => $job->getState(),
			'progress' => $job->getProgress(),
			'error' => $job->getError(),
			'priority' => $job->getPriority(),
			'attempts' => $job->getAttempts(),
			'max_attempts' => $job->getMaxAttempts(),
			'worker_pid' => $job->getWorkerPid(),
			'heartbeat_at' => $job->getHeartbeatAt(),
			'created_at' => $job->getCreatedAt(),
			'started_at' => $started,
			'finished_at' => $finished,
			'duration_seconds' => $duration,
			'chain' => $chain,
		];
	}

	private function typeLabel(string $type): string {
		return match ($type) {
			'fingerprint' => 'fingerprint',
			'encode', 'upgrade' => 'hls',
			'clip' => 'clip',
			'scan' => 'scan',
			default => $type,
		};
	}

	/** @return array<string, mixed> */
	private function buildChainHint(Job $job): array {
		$payload = json_decode($job->getPayload() ?? '{}', true) ?: [];
		$hint = ['label' => null, 'follow_up_job_id' => null, 'follow_up_state' => null];

		if ($job->getType() === 'fingerprint') {
			$auto = (bool)($payload['autoEncode'] ?? false);
			if ($auto) {
				$hint['label'] = 'next: HLS encode';
				$follow = $this->jobMapper->findFollowUpEncodeJob(
					(int)($job->getFileid() ?? 0),
					$job->getMediaId(),
					$job->getId(),
				);
				if ($follow !== null) {
					$hint['follow_up_job_id'] = $follow->getId();
					$hint['follow_up_state'] = $follow->getState();
					$hint['label'] = 'HLS encode → #' . $follow->getId();
				}
			}
		} elseif ($job->getType() === 'encode' || $job->getType() === 'upgrade') {
			$hint['label'] = 'HLS encode';
		} elseif ($job->getType() === 'clip') {
			$hint['label'] = 'clip export';
		}

		return $hint;
	}
}
