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
		private MediaIndexService $mediaIndex,
	) {
	}

	/** @return array<string, mixed> */
	public function serialize(Job $job): array {
		[$filename, $directory, $filesDir] = $this->resolvePath($job);

		$started = $job->getStartedAt();
		$finished = $job->getFinishedAt();
		$duration = null;
		if ($started !== null && $started > 0) {
			$end = ($finished !== null && $finished > 0) ? $finished : time();
			$duration = max(0, $end - $started);
		}

		$chain = $this->buildChainHint($job);
		$queuePosition = $this->jobMapper->countQueuedAhead($job);
		$etaSeconds = null;
		if ($job->getState() === 'running' && $started !== null && $started > 0) {
			$progress = max(1, min(99, (int)$job->getProgress()));
			$elapsed = time() - $started;
			if ($elapsed > 0) {
				$etaSeconds = (int)round($elapsed * (100 - $progress) / $progress);
			}
		}

		return [
			'id' => $job->getId(),
			'type' => $job->getType(),
			'type_label' => $this->typeLabel($job->getType() ?? ''),
			'fileid' => ($job->getFileid() ?? 0) > 0 ? (int)$job->getFileid() : null,
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
			'queue_position' => $job->getState() === 'queued' ? $queuePosition : null,
			'eta_seconds' => $etaSeconds,
		];
	}

	/** @return array{0: string, 1: string, 2: string} */
	private function resolvePath(Job $job): array {
		$filename = '';
		$directory = '/';
		$filesDir = '/';
		$fileid = (int)($job->getFileid() ?? 0);
		$userId = (string)($job->getUserId() ?? '');

		if ($fileid > 0) {
			$loc = $this->locationMapper->findByFileid($fileid);
			if ($loc !== null && $loc->getUserId() === $userId) {
				return $this->pathParts($loc->getPath());
			}
		}

		$mediaId = $job->getMediaId();
		if ($mediaId !== null && $mediaId > 0 && $userId !== '') {
			$loc = $this->locationMapper->findPrimaryForUserMedia($userId, $mediaId);
			if ($loc !== null) {
				return $this->pathParts($loc->getPath());
			}
		}

		if ($fileid > 0 && $userId !== '') {
			try {
				$file = $this->mediaIndex->resolveFileForUser($userId, $fileid);
				$internal = $file->getInternalPath();
				$path = '/' . ltrim($internal, '/');
				return $this->pathParts($path);
			} catch (\Throwable) {
			}
		}

		return [$filename, $directory, $filesDir];
	}

	/** @return array{0: string, 1: string, 2: string} */
	private function pathParts(string $path): array {
		$filename = basename($path);
		$directory = dirname($path);
		if ($directory === '.' || $directory === '\\') {
			$directory = '/';
		}
		return [$filename, $directory, $directory];
	}

	private function typeLabel(string $type): string {
		return match ($type) {
			'fingerprint' => 'fingerprint',
			'encode', 'upgrade' => 'hls',
			'clip' => 'clip',
			'scan' => 'scan',
			'adopt' => 'adopt',
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
				$hint['label'] = 'fingerprint → HLS encode';
				$follow = $this->jobMapper->findFollowUpEncodeJob(
					(int)($job->getFileid() ?? 0),
					$job->getMediaId(),
					$job->getId(),
				);
				if ($follow !== null) {
					$hint['follow_up_job_id'] = $follow->getId();
					$hint['follow_up_state'] = $follow->getState();
				}
			} else {
				$hint['label'] = 'fingerprint';
			}
		} elseif ($job->getType() === 'encode' || $job->getType() === 'upgrade') {
			$res = $payload['resolutions'] ?? [];
			$hint['label'] = is_array($res) && $res !== []
				? 'HLS ' . implode('/', $res)
				: 'HLS encode';
		} elseif ($job->getType() === 'clip') {
			$hint['label'] = 'clip export';
		}

		return $hint;
	}
}
