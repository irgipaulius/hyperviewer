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
				$result = $this->adoptFolder($entry, $userFolder, (string)$userId, $dryRun, $force);
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
		// Legacy 1.x app recorded every .cached_hls it created per user.
		$legacy = json_decode($this->config->getAppValue('hyperviewer', 'cached_hls_dirs_' . $userId, '[]'), true);
		if (is_array($legacy)) {
			foreach ($legacy as $loc) {
				if (is_string($loc)) {
					$paths[] = rtrim($loc, '/');
				}
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

	private function adoptFolder(Folder $cacheFolder, Folder $userFolder, string $userId, bool $dryRun, bool $force): string {
		$local = $cacheFolder->getStorage()->getLocalFile($cacheFolder->getInternalPath());
		if ($local === false || !$this->validateLegacyCacheComplete($local)) {
			return 'broken';
		}
		$candidates = $this->findSourceCandidates($userId, $userFolder, $cacheFolder, $local);
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
		if (!$force && $existingMedia !== null && $this->isStoreComplete($target)) {
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
			$media->setCacheState('none');
			$media->setCreatedAt(time());
			$media->setLastAccessAt(time());
			$media = $this->mediaMapper->insert($media);
		} else {
			$media->setCacheState('none');
			$this->mediaMapper->update($media);
		}
		$this->mediaIndex->indexOrRebind($file, $userId);
		$tempDir = $this->cacheStore->getTempEncodeDir($fp, random_int(100000, 999999999));
		$this->copyDir($local, $tempDir);
		if (!$this->validateLegacyCacheComplete($tempDir)) {
			$this->removeDir($tempDir);
			return 'broken';
		}
		if ($force && $target !== '' && is_dir($target)) {
			$this->cacheStore->removeMediaCache($fp);
		}
		$this->cacheStore->finalizeEncode($fp, $tempDir);
		$target = $this->cacheStore->getMediaDir($fp, false);
		if (!$this->isStoreComplete($target)) {
			return 'broken';
		}
		$media->setCacheState('ready');
		$media->setCacheBytes($this->cacheStore->computeCacheBytes($fp));
		$this->mediaMapper->update($media);
		return 'adopted';
	}

	private function userRelativePath(Folder $userFolder, Folder $node): string {
		$rel = $userFolder->getRelativePath($node->getPath());
		if ($rel === false || $rel === null || $rel === '') {
			return ltrim($node->getInternalPath(), '/');
		}
		return trim($rel, '/');
	}

	/** @return list<File> */
	private function findSourceCandidates(string $userId, Folder $userFolder, Folder $cacheFolder, string $cacheLocal): array {
		$baseName = $cacheFolder->getName();
		$baseStem = pathinfo($baseName, PATHINFO_FILENAME);
		$userRel = $this->userRelativePath($userFolder, $cacheFolder);
		$candidates = [];
		$fromProgress = $this->candidatesFromProgressJson($userFolder, $cacheLocal);
		if ($fromProgress !== []) {
			$candidates = array_merge($candidates, $fromProgress);
		}
		if (str_contains($userRel, '/.cached_hls/')) {
			[$videoDir] = explode('/.cached_hls/', $userRel, 2);
			$candidates = array_merge($candidates, $this->matchVideosNearCacheDir($userFolder, $videoDir, $baseStem, $baseName));
		} elseif (str_contains($userRel, '.cached_hls/')) {
			$before = explode('.cached_hls/', $userRel, 2)[0];
			$videoDir = rtrim($before, '/');
			if ($videoDir !== '') {
				$candidates = array_merge($candidates, $this->matchVideosNearCacheDir($userFolder, $videoDir, $baseStem, $baseName));
			}
			$candidates = array_merge($candidates, $this->searchUserVideosByBasename($userId, $userFolder, $baseStem, $baseName));
		} elseif (str_starts_with($userRel, '.cached_hls/') || $userRel === '.cached_hls') {
			$candidates = array_merge($candidates, $this->searchUserVideosByBasename($userId, $userFolder, $baseStem, $baseName));
		} else {
			$parentRel = dirname($userRel);
			if ($parentRel !== '.' && $parentRel !== '') {
				$candidates = array_merge($candidates, $this->matchVideosNearCacheDir($userFolder, $parentRel, $baseStem, $baseName));
			}
		}
		return $this->uniqueFiles($candidates);
	}

	/** @return list<File> */
	private function matchVideosNearCacheDir(Folder $userFolder, string $relDir, string $baseStem, string $baseName): array {
		$relDir = trim($relDir, '/');
		if ($relDir === '' || !$userFolder->nodeExists($relDir)) {
			return [];
		}
		$folder = $userFolder->get($relDir);
		if (!$folder instanceof Folder) {
			return [];
		}
		$out = $this->matchVideosInFolder($folder, $baseStem, $baseName);
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder || $node->getName() === '.cached_hls') {
				continue;
			}
			$out = array_merge($out, $this->matchVideosInFolder($node, $baseStem, $baseName));
		}
		$parentRel = dirname($relDir);
		if ($parentRel !== '.' && $parentRel !== '' && $userFolder->nodeExists($parentRel)) {
			$parent = $userFolder->get($parentRel);
			if ($parent instanceof Folder) {
				foreach ($parent->getDirectoryListing() as $node) {
					if (!$node instanceof Folder || $node->getName() === '.cached_hls') {
						continue;
					}
					$out = array_merge($out, $this->matchVideosInFolder($node, $baseStem, $baseName));
				}
			}
		}
		return $out;
	}

	/** @return list<File> */
	private function matchVideosInFolder(Folder $folder, string $baseStem, string $baseName): array {
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

	/** @var array<string, array<string, list<File>>> per-user index: lowercase stem/name => files */
	private array $videoIndex = [];

	/** @return list<File> */
	private function searchUserVideosByBasename(string $userId, Folder $userFolder, string $baseStem, string $baseName): array {
		if (!isset($this->videoIndex[$userId])) {
			$roots = [''];
			foreach ($this->watchDirMapper->findEnabledForUser($userId) as $watch) {
				$roots[] = trim($watch->getPath(), '/');
			}
			$index = [];
			foreach (array_values(array_unique($roots)) as $rootRel) {
				$this->indexVideos($userFolder, $rootRel, 0, 12, $index);
			}
			$this->videoIndex[$userId] = $index;
		}
		$index = $this->videoIndex[$userId];
		return array_merge($index[strtolower($baseStem)] ?? [], strtolower($baseName) !== strtolower($baseStem) ? ($index[strtolower($baseName)] ?? []) : []);
	}

	/** Walks the tree once, indexing video files by lowercase stem and by lowercase full name. */
	private function indexVideos(Folder $userFolder, string $relPath, int $depth, int $maxDepth, array &$index): void {
		if ($depth > $maxDepth) {
			return;
		}
		$relPath = trim($relPath, '/');
		if ($relPath !== '' && !$userFolder->nodeExists($relPath)) {
			return;
		}
		$folder = $relPath === '' ? $userFolder : $userFolder->get($relPath);
		if (!$folder instanceof Folder) {
			return;
		}
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				if ($this->isVideoFile($node)) {
					$stem = strtolower(pathinfo($node->getName(), PATHINFO_FILENAME));
					$name = strtolower($node->getName());
					$index[$stem][] = $node;
					if ($name !== $stem) {
						$index[$name][] = $node;
					}
				}
				continue;
			}
			if ($node instanceof Folder && $node->getName() !== '.cached_hls') {
				$childRel = $relPath === '' ? $node->getName() : $relPath . '/' . $node->getName();
				$this->indexVideos($userFolder, $childRel, $depth + 1, $maxDepth, $index);
			}
		}
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
		$cacheDur = $this->cacheDurationFromOnePlaylist($cacheLocal);
		if ($cacheDur <= 0) {
			return $candidates;
		}
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

	private function cacheDurationFromOnePlaylist(string $cacheLocal): float {
		$playlist = $this->pickSingleVariantPlaylist($cacheLocal);
		if ($playlist === null) {
			return 0.0;
		}
		return $this->sumExtinfFile($playlist);
	}

	private function pickSingleVariantPlaylist(string $cacheLocal): ?string {
		if (is_file($cacheLocal . '/master.m3u8')) {
			$lines = file($cacheLocal . '/master.m3u8', FILE_IGNORE_NEW_LINES) ?: [];
			for ($i = 0, $c = count($lines); $i < $c; $i++) {
				if (str_starts_with($lines[$i], '#EXT-X-STREAM-INF')) {
					for ($j = $i + 1; $j < $c; $j++) {
						$line = trim($lines[$j]);
						if ($line === '' || str_starts_with($line, '#')) {
							continue;
						}
						$candidate = $cacheLocal . '/' . $line;
						if (is_file($candidate)) {
							return $candidate;
						}
						break;
					}
				}
			}
		}
		if (is_file($cacheLocal . '/playlist.m3u8')) {
			return $cacheLocal . '/playlist.m3u8';
		}
		$variants = glob($cacheLocal . '/playlist_*.m3u8') ?: [];
		return $variants[0] ?? null;
	}

	private function sumExtinfFile(string $playlistPath): float {
		$content = file_get_contents($playlistPath);
		if ($content === false) {
			return 0.0;
		}
		$total = 0.0;
		if (preg_match_all('/#EXTINF:([\d.]+)/', $content, $m)) {
			foreach ($m[1] as $sec) {
				$total += (float)$sec;
			}
		}
		return $total;
	}

	private function validateLegacyCacheComplete(string $dir): bool {
		$playlist = $this->pickSingleVariantPlaylist($dir);
		if ($playlist === null) {
			return false;
		}
		$content = file_get_contents($playlist);
		if ($content === false || !str_contains($content, '#EXT-X-ENDLIST')) {
			return false;
		}
		$base = dirname($playlist);
		if (preg_match_all('/\n([^#\n][^\n]*\.ts)\b/', $content, $m)) {
			foreach ($m[1] as $seg) {
				$seg = trim($seg);
				if (!is_file($base . '/' . $seg) && !is_file($dir . '/' . $seg)) {
					return false;
				}
			}
		}
		return is_file($dir . '/master.m3u8') || is_file($dir . '/playlist.m3u8') || (glob($dir . '/playlist_*.m3u8') ?: []) !== [];
	}

	private function isStoreComplete(string $target): bool {
		if ($target === '' || !is_dir($target)) {
			return false;
		}
		return $this->validateLegacyCacheComplete($target);
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

	private function removeDir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($dir);
	}
}
