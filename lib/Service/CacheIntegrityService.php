<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\MediaMapper;

class CacheIntegrityService {
	public function __construct(
		private MediaMapper $mediaMapper,
		private CacheStoreService $cacheStore,
	) {
	}

	/** @return array{checked: int, missing_on_disk: int, orphan_dirs_sample: int} */
	public function verifyReadyCaches(int $sampleLimit = 500): array {
		$rows = $this->mediaMapper->listReadyFingerprints($sampleLimit);
		$missing = 0;
		foreach ($rows as $fp) {
			$path = $this->cacheStore->resolveFilePath($fp, 'master.m3u8')
				?? $this->cacheStore->resolveFilePath($fp, 'playlist.m3u8');
			if ($path === null) {
				$missing++;
			}
		}
		return [
			'checked' => count($rows),
			'missing_on_disk' => $missing,
			'orphan_dirs_sample' => 0,
		];
	}
}
