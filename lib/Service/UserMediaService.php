<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\MediaMapper;

class UserMediaService {
	public function __construct(
		private MediaIndexService $mediaIndex,
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private CacheStoreService $cacheStore,
		private QueueService $queueService,
		private ConfigService $config,
	) {
	}

	public function pin(string $userId, int $fileid, bool $pinned): void {
		$file = $this->mediaIndex->resolveFileForUser($userId, $fileid);
		$media = $this->mediaIndex->getMediaForFile($file, $userId);
		if ($media === null) {
			throw new \RuntimeException('Media not indexed');
		}
		$media->setPinned($pinned);
		$this->mediaMapper->update($media);
	}

	public function regenerate(string $userId, int $fileid): int {
		$this->mediaIndex->resolveFileForUser($userId, $fileid);
		$job = $this->queueService->enqueueFingerprint($userId, $fileid, [
			'autoEncode' => true,
			'resolutions' => $this->config->getUserAllowedRenditions(),
		]);
		return $job->getId();
	}

	public function deleteCacheForUser(string $userId, int $fileid): void {
		$file = $this->mediaIndex->resolveFileForUser($userId, $fileid);
		$media = $this->mediaIndex->getMediaForFile($file, $userId);
		if ($media === null) {
			return;
		}
		$loc = $this->locationMapper->findByFileid($fileid);
		if ($loc !== null && $loc->getUserId() !== $userId) {
			throw new \RuntimeException('Forbidden');
		}
		$this->cacheStore->removeMediaCache($media->getFingerprint());
		$media->setCacheState('none');
		$media->setCacheBytes(0);
		$media->setRenditions(null);
		$this->mediaMapper->update($media);
	}
}
