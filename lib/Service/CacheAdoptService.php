<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Media;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\Location;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class CacheAdoptService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private FingerprintService $fingerprintService,
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private CacheStoreService $cacheStore,
		private FfmpegRunner $ffmpeg,
	) {
	}

	/** @param array<string, mixed> $options */
	public function run(array $options): array {
		$report = ['adopted' => 0, 'ambiguous' => 0, 'broken' => 0, 'orphaned' => 0, 'skipped' => 0];
		$userId = $options['user'] ?? null;
		$limit = $options['limit'] ?? null;
		$dryRun = (bool)($options['dry_run'] ?? false);
		$cleanup = (bool)($options['cleanup_adopted'] ?? false);
		if ($cleanup) {
			return ['cleanup' => 'not implemented in dry pass', 'report' => $report];
		}
		$paths = [];
		if (!empty($options['path'])) {
			$paths[] = (string)$options['path'];
		}
		if ($userId !== null) {
			$json = $this->config->getUserValue((string)$userId, 'hyperviewer', 'cache_locations', '[]');
			$locs = json_decode($json, true);
			if (is_array($locs)) {
				foreach ($locs as $loc) {
					$paths[] = rtrim((string)$loc, '/');
				}
			}
			$paths[] = '/.cached_hls';
		}
		$paths = array_unique($paths);
		$count = 0;
		foreach ($paths as $cacheRoot) {
			if ($userId === null) {
				continue;
			}
			$userFolder = $this->rootFolder->getUserFolder((string)$userId);
			$rel = ltrim($cacheRoot, '/');
			if (!$userFolder->nodeExists($rel)) {
				continue;
			}
			$folder = $userFolder->get($rel);
			if (!$folder instanceof \OCP\Files\Folder) {
				continue;
			}
			foreach ($folder->getDirectoryListing() as $entry) {
				if (!$entry instanceof \OCP\Files\Folder) {
					continue;
				}
				if ($limit !== null && $count >= $limit) {
					break 2;
				}
				$result = $this->adoptFolder($entry, (string)$userId, $dryRun);
				$report[$result]++;
				$count++;
			}
		}
		return $report;
	}

	private function adoptFolder(\OCP\Files\Folder $cacheFolder, string $userId, bool $dryRun): string {
		$name = $cacheFolder->getName();
		$local = $cacheFolder->getStorage()->getLocalFile($cacheFolder->getInternalPath());
		if ($local === false) {
			return 'broken';
		}
		if (!is_file($local . '/master.m3u8') && !is_file($local . '/playlist.m3u8') && glob($local . '/playlist_*.m3u8') === []) {
			return 'broken';
		}
		$candidates = $this->findSourceCandidates($userId, $name, $cacheFolder);
		if ($candidates === []) {
			return 'orphaned';
		}
		if (count($candidates) > 1) {
			$candidates = $this->disambiguateByDuration($local, $candidates);
			if (count($candidates) !== 1) {
				return 'ambiguous';
			}
		}
		$file = $candidates[0];
		try {
			$fp = $this->fingerprintService->computeFromFile($file);
		} catch (\Throwable) {
			return 'broken';
		}
		if ($dryRun) {
			return 'adopted';
		}
		$media = $this->mediaMapper->findByFingerprint($fp);
		if ($media === null) {
			$media = new Media();
			$media->setFingerprint($fp);
			$media->setSize($file->getSize());
			$media->setCacheState('ready');
			$media->setCreatedAt(time());
			$media->setLastAccessAt(time());
			$media = $this->mediaMapper->insert($media);
		}
		$loc = $this->locationMapper->findByFileid($file->getId());
		if ($loc === null) {
			$loc = new Location();
			$loc->setMediaId($media->getId());
			$loc->setFileid($file->getId());
			$loc->setStorageId((int)$file->getStorageId());
			$internal = ltrim(str_replace('/' . $userId . '/files', '', $file->getPath()), '/');
			$loc->setPath($internal);
			$loc->setPathHash(hash('sha256', $internal));
			$loc->setUserId($userId);
			$loc->setSize($file->getSize());
			$loc->setMtime($file->getMTime());
			$loc->setSeenAt(time());
			$this->locationMapper->insert($loc);
		}
		$target = $this->cacheStore->getMediaDir($fp, true);
		$this->copyDir($local, $target);
		$media->setCacheBytes($this->cacheStore->computeCacheBytes($fp));
		$this->mediaMapper->update($media);
		return 'adopted';
	}

	/** @return list<\OCP\Files\File> */
	private function findSourceCandidates(string $userId, string $baseName, \OCP\Files\Folder $cacheFolder): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$parent = dirname($cacheFolder->getPath());
		$out = [];
		foreach (['.mp4', '.mov', '.MP4', '.MOV'] as $ext) {
			$try = ltrim(str_replace($userFolder->getPath(), '', $parent), '/') . '/' . $baseName . $ext;
			if ($userFolder->nodeExists($try)) {
				$node = $userFolder->get($try);
				if ($node instanceof \OCP\Files\File) {
					$out[] = $node;
				}
			}
		}
		return $out;
	}

	/** @param list<\OCP\Files\File> $candidates */
	private function disambiguateByDuration(string $cacheLocal, array $candidates): array {
		$cacheDur = $this->sumExtinf($cacheLocal);
		$matched = [];
		foreach ($candidates as $file) {
			$local = $file->getStorage()->getLocalFile($file->getInternalPath());
			if ($local === false) {
				continue;
			}
			try {
				$json = $this->ffmpeg->runFfprobe(['-v', 'quiet', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $local]);
				$dur = (float)trim($json);
				if (abs($dur - $cacheDur) <= 0.5) {
					$matched[] = $file;
				}
			} catch (\Throwable) {
				continue;
			}
		}
		return $matched;
	}

	private function sumExtinf(string $cacheLocal): float {
		$total = 0.0;
		$files = array_merge(
			glob($cacheLocal . '/*.m3u8') ?: [],
			glob($cacheLocal . '/playlist_*.m3u8') ?: [],
		);
		foreach ($files as $pl) {
			$content = file_get_contents($pl);
			if ($content === false) {
				continue;
			}
			if (preg_match_all('/#EXTINF:([\d.]+)/', $content, $m)) {
				foreach ($m[1] as $sec) {
					$total += (float)$sec;
				}
			}
		}
		return $total;
	}

	private function copyDir(string $src, string $dst): void {
		if (!is_dir($dst)) {
			mkdir($dst, 0775, true);
		}
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			$rel = substr($file->getPathname(), strlen($src) + 1);
			$target = $dst . '/' . $rel;
			if ($file->isDir()) {
				if (!is_dir($target)) {
					mkdir($target, 0775, true);
				}
			} else {
				copy($file->getPathname(), $target);
			}
		}
	}
}
