<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\WatchDir;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

class WatchScanService {
	public function __construct(
		private IRootFolder $rootFolder,
		private VideoCollectionService $videoCollection,
		private MediaIndexService $mediaIndex,
		private QueueService $queueService,
		private JobMapper $jobMapper,
		private WatchDirMapper $watchDirMapper,
	) {
	}

	public function processScanJob(Job $job): void {
		$payload = json_decode($job->getPayload() ?? '{}', true) ?: [];
		$path = (string)($payload['path'] ?? '/');
		$watchId = isset($payload['watchId']) ? (int)$payload['watchId'] : null;
		$overwrite = (bool)($payload['overwrite'] ?? false);
		$userId = (string)$job->getUserId();
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$rel = ltrim($path, '/');
		if (!$userFolder->nodeExists($rel)) {
			return;
		}
		$folder = $userFolder->get($rel);
		if (!$folder instanceof Folder) {
			return;
		}
		$watch = null;
		if ($watchId !== null && $watchId > 0) {
			foreach ($this->watchDirMapper->findAllForUser($userId) as $candidate) {
				if ($candidate->getId() === $watchId) {
					$watch = $candidate;
					break;
				}
			}
		}
		$resolutions = $watch !== null
			? (json_decode($watch->getResolutions() ?? '[]', true) ?: [])
			: [];
		if ($resolutions === []) {
			$resolutions = ['720p', '480p', '360p', '240p'];
		}
		$entries = $this->videoCollection->collectInFolder($folder, $userFolder);
		foreach ($entries as $entry) {
			$this->maybeEnqueueFingerprint(
				$userId,
				(int)$entry['fileid'],
				$resolutions,
				$overwrite,
				$watchId,
			);
		}
		if ($watch !== null) {
			$watch->setLastScanAt(time());
			$this->watchDirMapper->update($watch);
		}
	}

	private function maybeEnqueueFingerprint(
		string $userId,
		int $fileid,
		array $resolutions,
		bool $overwrite,
		?int $watchId,
	): void {
		if ($this->jobMapper->hasActivePipelineForFileid($fileid)) {
			return;
		}
		try {
			$file = $this->mediaIndex->resolveFileForUser($userId, $fileid);
		} catch (\Throwable) {
			return;
		}
		$media = $this->mediaIndex->getMediaForFile($file, $userId);
		if ($media !== null) {
			if ($media->getAutoDisabled()) {
				return;
			}
			$state = $media->getCacheState();
			if (!$overwrite && in_array($state, ['ready', 'partial'], true)) {
				return;
			}
			if (!$overwrite && $this->jobMapper->hasActiveForMedia('encode', $media->getId())) {
				return;
			}
		}
		$payload = [
			'autoEncode' => true,
			'resolutions' => $resolutions,
			'overwrite' => $overwrite,
		];
		if ($watchId !== null) {
			$payload['watchId'] = $watchId;
		}
		try {
			$this->queueService->enqueueFingerprint($userId, $fileid, $payload);
		} catch (\Throwable) {
			// duplicate or queue full — skip
		}
	}
}
