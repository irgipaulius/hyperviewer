<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\WatchDir;
use OCA\HyperViewer\Db\WatchDirMapper;

class WatchDirectoryService {
	public function __construct(
		private WatchDirMapper $watchDirMapper,
		private QueueService $queueService,
		private UserPathService $userPaths,
	) {
	}

	/**
	 * @return array{watch: WatchDir, created: bool, scanQueued: bool}
	 */
	public function register(
		string $userId,
		string $directory,
		array $resolutions,
	): array {
		$directory = $this->userPaths->normalizeWatchPath($directory, $userId);
		$upsert = $this->watchDirMapper->upsert($userId, $directory, null, $resolutions, true, 100);
		$scanQueued = false;
		if ($upsert['created']) {
			try {
				$this->queueService->enqueueScan($userId, $directory, $upsert['watch']->getId());
				$scanQueued = true;
			} catch (\Throwable) {
			}
		}
		return [
			'watch' => $upsert['watch'],
			'created' => $upsert['created'],
			'scanQueued' => $scanQueued,
		];
	}
}
