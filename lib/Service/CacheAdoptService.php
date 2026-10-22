<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Media;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class CacheAdoptService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private FingerprintService $fingerprintService,
		private MediaMapper $mediaMapper,
		private CacheStoreService $cacheStore,
		private FfmpegRunner $ffmpeg,
		private WatchDirMapper $watchDirMapper,
		private MediaIndexService $mediaIndex,
	) {
	}

	/** @param array<string, mixed> $options */
	public function run(array $options): array {
		$report = ['adopted' => 0, 'ambiguous' => 0, 'broken' => 0, 'orphaned' => 0, 'skipped' => 0];
		$userId = $options['user'] ?? null;
		if ($userId === null || $userId === '') {
			return $report + ['error' => 'user required'];
		}
		$limit = $options['limit'] ?? null;
		$dryRun = (bool)($options['dry_run'] ?? false);
		$force = (bool)($options['force'] ?? false);
		$cleanup = (bool)($options['cleanup_adopted'] ?? false);
		if ($cleanup) {
			return ['cleanup' => 'not implemented in dry pass', 'report' => $report];
		}
		$paths = $this->collectCacheRoots((string)$userId, $options['path'] ?? null);
		$count = 0;
		$userFolder = $this->rootFolder->getUserFolder((string)$userId);
		foreach ($paths as $cacheRoot) {
			$rel = ltrim($cacheRoot, '/');
			if ($rel === '' || !$userFolder->nodeExists($rel)) {
				continue;
			}
			$folder = $userFolder->get($rel);
			if (!$folder instanceof Folder) {
				continue;
			}
			foreach ($folder->getDirectoryListing() as $entry) {
				if (!$entry instanceof Folder) {
					continue;
				}
				if ($limit !== null && $count >= $limit) {
					break 2;
				}
				$result = $this->adoptFolder($entry, (string)$userId, $dryRun, $force);
				$report[$result]++;
				$count++;
			}
		}
		return $report;
	}

	/** @return list<string> */
	private function collectCacheRoots(string $userId, mixed $explicitPath): array {
		$paths = [];
		if ($explicitPath !== null && $explicitPath !== '') {
			$paths[] = rtrim((string)$explicitPath, '/');
		}
		$json = $this->config->getUserValue($userId, 'hyperviewer', 'cache_locations', '[]');
		$locs = json_decode($json, true);
		if (is_array($locs)) {
			foreach ($locs as $loc) {
				$paths[] = rtrim((string)$loc, '/');
			}
		}
		foreach ($this->watchDirMapper->findEnabledForUser($userId) as $watch) {
			$watchPath = trim($watch->getPath(), '/');
			if ($watchPath !== '') {
				$paths[] = dirname($watchPath) . '/.cached_hls';
				$paths[] = $watchPath . '/.cached_hls';
			}
		}
		$paths[] = '.cached_hls';
		return array_values(array_unique(array_filter($paths)));
	}

	private function adoptFolder(Folder $cacheFolder, string $userId, bool $dryRun, bool $force): string {
		$local = $cacheFolder->getStorage()->getLocalFile($cacheFolder->getInternalPath());
		if ($local === false) {
			return 'broken';
		}
		if (!is_file($local . '/master.m3u8') && !is_file($local . '/playlist.m3u8') && (glob($local . '/playlist_*.m3u8') ?: []) === []) {
			return 'broken';
		}
		$candidates = $this->findSourceCandidates($userId, $cacheFolder, $local);
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
		$existingMedia = $this->mediaMapper->findByFingerprint($fp);
		$target = $this->cacheStore->getMediaDir($fp, false);
		if (!$force && $existingMedia !== null && $existingMedia->getCacheState() === 'ready'
			&& $target !== '' && is_dir($target) && (is_file($target . '/master.m3u8') || is_file($target . '/playlist.m3u8'))) {
			if (!$dryRun) {
				$this->mediaIndex->indexOrRebind($file, $userId);
			}
			return 'skipped';
		}
		if ($dryRun) {
			return 'adopted';
		}
		$media = $existingMedia;
		if ($media === null) {
			$media = new Media();
			$media->setFingerprint($fp);
			$media->setSize($file->getSize());
			$media->setCacheState('ready');
			$media->setCreatedAt(time());
			$media->setLastAccessAt(time());
			$media = $this->mediaMapper->insert($media);
		}
		$this->mediaIndex->indexOrRebind($file, $userId);
		if ($force && $target !== '' && is_dir($target)) {
			$this->cacheStore->removeMediaCache($fp);
		}
		$target = $this->cacheStore->getMediaDir($fp, true);
		if (!is_file($target . '/master.m3u8') && !is_file($target . '/playlist.m3u8')) {
			$this->copyDir($local, $target);
		}
		$media->setCacheState('ready');
		$media->setCacheBytes($this->cacheStore->computeCacheBytes($fp));
		$this->mediaMapper->update($media);
		return 'adopted';
	}

	/** @return list<File> */
	private function findSourceCandidates(string $userId, Folder $cacheFolder, string $cacheLocal): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$baseName = $cacheFolder->getName();
		$baseStem = pathinfo($baseName, PATHINFO_FILENAME);
		$internal = $cacheFolder->getInternalPath();
		$candidates = [];
		$fromProgress = $this->candidatesFromProgressJson($userFolder, $cacheLocal);
		if ($fromProgress !== []) {
			$candidates = array_merge($candidates, $fromProgress);
		}
		if (str_contains($internal, '/.cached_hls/')) {
			[$videoDir] = explode('/.cached_hls/', $internal, 2);
			$candidates = array_merge($candidates, $this->matchVideosInRelativeDir($userFolder, $videoDir, $baseStem, $baseName));
		} elseif (str_starts_with($internal, '.cached_hls/') || $internal === '.cached_hls') {
			$candidates = array_merge($candidates, $this->searchUserVideosByBasename($userId, $userFolder, $baseStem, $baseName));
		} else {
			$parentRel = dirname($internal);
			if ($parentRel !== '.' && $parentRel !== '') {
				$candidates = array_merge($candidates, $this->matchVideosInRelativeDir($userFolder, $parentRel, $baseStem, $baseName));
			}
		}
		return $this->uniqueFiles($candidates);
	}

	/** @return list<File> */
	private function candidatesFromProgressJson(Folder $userFolder, string $cacheLocal): array {
		$path = $cacheLocal . '/progress.json';
		if (!is_file($path)) {
			return [];
		}
		$data = json_decode((string)file_get_contents($path), true);
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach (['relativePath', 'sourcePath', 'originalPath', 'path'] as $key) {
			if (empty($data[$key]) || !is_string($data[$key])) {
				continue;
			}
			$rel = ltrim($data[$key], '/');
			if ($userFolder->nodeExists($rel)) {
				$node = $userFolder->get($rel);
				if ($node instanceof File && $this->isVideoFile($node)) {
					$out[] = $node;
				}
			}
		}
		if (!empty($data['filename']) && is_string($data['filename'])) {
			$dir = is_string($data['directory'] ?? null) ? trim($data['directory'], '/') : '';
			$rel = ltrim(($dir !== '' ? $dir . '/' : '') . $data['filename'], '/');
			if ($userFolder->nodeExists($rel)) {
				$node = $userFolder->get($rel);
				if ($node instanceof File && $this->isVideoFile($node)) {
					$out[] = $node;
				}
			}
		}
		return $out;
	}

	/** @return list<File> */
	private function matchVideosInRelativeDir(Folder $userFolder, string $relDir, string $baseStem, string $baseName): array {
		$relDir = trim($relDir, '/');
		if ($relDir === '' || !$userFolder->nodeExists($relDir)) {
			return [];
		}
		$folder = $userFolder->get($relDir);
		if (!$folder instanceof Folder) {
			return [];
		}
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof File || !$this->isVideoFile($node)) {
				continue;
			}
			$stem = pathinfo($node->getName(), PATHINFO_FILENAME);
			if (strcasecmp($stem, $baseStem) === 0 || strcasecmp($node->getName(), $baseName) === 0) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/** @return list<File> */
	private function searchUserVideosByBasename(string $userId, Folder $userFolder, string $baseStem, string $baseName): array {
		$roots = [''];
		foreach ($this->watchDirMapper->findEnabledForUser($userId) as $watch) {
			$roots[] = trim($watch->getPath(), '/');
		}
		$roots = array_values(array_unique($roots));
		$out = [];
		foreach ($roots as $rootRel) {
			$out = array_merge($out, $this->walkForBasename($userFolder, $rootRel, $baseStem, $baseName, 0, 12));
		}
		return $out;
	}

	/** @return list<File> */
	private function walkForBasename(Folder $userFolder, string $relPath, string $baseStem, string $baseName, int $depth, int $maxDepth): array {
		if ($depth > $maxDepth) {
			return [];
		}
		$relPath = trim($relPath, '/');
		if ($relPath !== '' && !$userFolder->nodeExists($relPath)) {
			return [];
		}
		$folder = $relPath === '' ? $userFolder : $userFolder->get($relPath);
		if (!$folder instanceof Folder) {
			return [];
		}
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				if (!$this->isVideoFile($node)) {
					continue;
				}
				$stem = pathinfo($node->getName(), PATHINFO_FILENAME);
				if (strcasecmp($stem, $baseStem) === 0 || strcasecmp($node->getName(), $baseName) === 0) {
					$out[] = $node;
				}
				continue;
			}
			if ($node instanceof Folder && $node->getName() !== '.cached_hls') {
				$childRel = $relPath === '' ? $node->getName() : $relPath . '/' . $node->getName();
				$out = array_merge($out, $this->walkForBasename($userFolder, $childRel, $baseStem, $baseName, $depth + 1, $maxDepth));
			}
		}
		return $out;
	}

	private function isVideoFile(File $file): bool {
		return in_array($file->getMimeType(), ['video/mp4', 'video/quicktime'], true);
	}

	/** @param list<File> $files */
	private function uniqueFiles(array $files): array {
		$seen = [];
		$out = [];
		foreach ($files as $file) {
			$key = $file->getInternalPath() . ':' . $file->getId();
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $file;
		}
		return $out;
	}

	/** @param list<File> $candidates */
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
			} elseif (!is_file($target)) {
				copy($file->getPathname(), $target);
			}
		}
	}
}
