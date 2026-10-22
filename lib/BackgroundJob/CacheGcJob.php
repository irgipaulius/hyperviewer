<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Service\CacheStoreService;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Db\LogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CacheGcJob extends TimedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private ConfigService $config,
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private CacheStoreService $cacheStore,
		private LogMapper $logMapper,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(86400);
	}

	protected function run($argument): void {
		$graceDays = $this->config->getInt(ConfigService::KEY_GC_GRACE_DAYS);
		$candidates = $this->mediaMapper->findCandidatesForGc($graceDays * 86400);
		foreach ($candidates as $media) {
			if ($this->locationMapper->countByMediaId($media->getId()) > 0) {
				continue;
			}
			$this->cacheStore->removeMediaCache($media->getFingerprint());
			$this->mediaMapper->delete($media);
		}
		$retention = $this->config->getInt(ConfigService::KEY_LOG_RETENTION_DAYS) * 86400;
		if ($retention > 0) {
			$this->logMapper->pruneOlderThan($retention);
		}
	}
}
