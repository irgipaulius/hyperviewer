<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service to cache locations of .cached_hls directories
 * Reduces filesystem I/O by scanning once per day instead of on every request
 */
class CachedHlsDirectoryService {

	private const CACHE_KEY_PREFIX = 'cached_hls_dirs_';
	private const CACHE_TIMESTAMP_PREFIX = 'cached_hls_timestamp_';
	private const CACHE_MAX_AGE = 86400; // 24 hours in seconds

	private IConfig $config;
	private IRootFolder $rootFolder;
	private LoggerInterface $logger;

	public function __construct(
		IConfig $config,
		IRootFolder $rootFolder,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
	}

	/**
	 * Get cached directory paths for a user
	 * Returns array of relative paths to .cached_hls directories
	 */
	public function getCachedDirectories(string $userId): array {
		$cacheKey = self::CACHE_KEY_PREFIX . $userId;
		$cached = $this->config->getAppValue('hyperviewer', $cacheKey, '');

		if (empty($cached)) {
			// Cache doesn't exist, create it
			$this->refreshCache($userId);
			$cached = $this->config->getAppValue('hyperviewer', $cacheKey, '');
		}

		if (empty($cached)) {
			return [];
		}

		$dirs = json_decode($cached, true);
		return is_array($dirs) ? $dirs : [];
	}

	/**
	 * Scan filesystem and update cache
	 * Uses find command for fast scanning
	 */
	public function refreshCache(string $userId): void {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$userPath = $userFolder->getStorage()->getLocalFile($userFolder->getInternalPath());

			if (!$userPath || !is_dir($userPath)) {
				$this->logger->warning('Could not get local path for user folder', ['userId' => $userId]);
				return;
			}

			// Use find command to locate all .cached_hls directories
			// -type d: directories only
			// -name .cached_hls: exact name match
			$findCmd = sprintf(
				'find %s -type d -name %s 2>/dev/null',
				escapeshellarg($userPath),
				escapeshellarg('.cached_hls')
			);

			$output = shell_exec($findCmd);
			
			if ($output === null) {
				$this->logger->error('Find command failed', ['userId' => $userId, 'cmd' => $findCmd]);
				return;
			}

			// Parse output and convert to relative paths
			$absolutePaths = array_filter(explode("\n", trim($output)));
			$relativePaths = [];

			foreach ($absolutePaths as $absPath) {
				// Convert absolute path to relative path within user folder
				if (strpos($absPath, $userPath) === 0) {
					$relPath = substr($absPath, strlen($userPath));
					$relPath = ltrim($relPath, '/');
					if (!empty($relPath)) {
						$relativePaths[] = $relPath;
					}
				}
			}

			// Store in config
			$cacheKey = self::CACHE_KEY_PREFIX . $userId;
			$timestampKey = self::CACHE_TIMESTAMP_PREFIX . $userId;
			
			$this->config->setAppValue('hyperviewer', $cacheKey, json_encode($relativePaths));
			$this->config->setAppValue('hyperviewer', $timestampKey, (string)time());

			$this->logger->info('Refreshed .cached_hls directory cache', [
				'userId' => $userId,
				'dirCount' => count($relativePaths)
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to refresh cache', [
				'userId' => $userId,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Add a single directory to the cache
	 * Called when a new .cached_hls directory is created
	 */
	public function addDirectory(string $userId, string $path): void {
		try {
			$cacheKey = self::CACHE_KEY_PREFIX . $userId;
			$cached = $this->config->getAppValue('hyperviewer', $cacheKey, '');

			$dirs = [];
			if (!empty($cached)) {
				$dirs = json_decode($cached, true);
				if (!is_array($dirs)) {
					$dirs = [];
				}
			}

			// Normalize path (remove leading/trailing slashes)
			$normalizedPath = trim($path, '/');

			// Add if not already in cache
			if (!in_array($normalizedPath, $dirs, true)) {
				$dirs[] = $normalizedPath;
				$this->config->setAppValue('hyperviewer', $cacheKey, json_encode($dirs));
				
				$this->logger->debug('Added directory to cache', [
					'userId' => $userId,
					'path' => $normalizedPath
				]);
			}

		} catch (\Exception $e) {
			$this->logger->error('Failed to add directory to cache', [
				'userId' => $userId,
				'path' => $path,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Get cache age in seconds
	 */
	public function getCacheAge(string $userId): int {
		$timestampKey = self::CACHE_TIMESTAMP_PREFIX . $userId;
		$timestamp = $this->config->getAppValue('hyperviewer', $timestampKey, '0');
		
		$cacheTime = (int)$timestamp;
		if ($cacheTime === 0) {
			return PHP_INT_MAX; // Cache doesn't exist, return max age
		}

		return time() - $cacheTime;
	}

	/**
	 * Check if cache should be refreshed (older than 24 hours)
	 */
	public function shouldRefresh(string $userId): bool {
		return $this->getCacheAge($userId) > self::CACHE_MAX_AGE;
	}

	/**
	 * Clear cache for a user (useful for testing/debugging)
	 */
	public function clearCache(string $userId): void {
		$cacheKey = self::CACHE_KEY_PREFIX . $userId;
		$timestampKey = self::CACHE_TIMESTAMP_PREFIX . $userId;
		
		$this->config->deleteAppValue('hyperviewer', $cacheKey);
		$this->config->deleteAppValue('hyperviewer', $timestampKey);
		
		$this->logger->info('Cleared cache', ['userId' => $userId]);
	}

	/**
	 * Check if HLS cache exists at a specific path
	 * Checks for master.m3u8 or playlist.m3u8 files
	 */
	public function cacheExistsAt($userFolder, string $cachePath): bool {
		try {
			if ($userFolder->nodeExists($cachePath . '/master.m3u8')) {
				return true;
			}
			if ($userFolder->nodeExists($cachePath . '/playlist.m3u8')) {
				return true;
			}
		} catch (\Exception $e) {
			// Ignore errors
		}
		return false;
	}

	/**
	 * Find HLS cache for a video file
	 * Uses cached directory locations for fast lookup
	 * 
	 * @param mixed $userFolder The user's folder object
	 * @param string $filename The video filename
	 * @param string $userId The user ID
	 * @return string|null The cache path if found, null otherwise
	 */
	public function findHlsCache($userFolder, string $filename, string $userId): ?string {
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		
		// Get all cached .cached_hls directories
		$cachedDirs = $this->getCachedDirectories($userId);
		
		// Check each cached location for the video cache
		foreach ($cachedDirs as $cacheDir) {
			$cachePath = $cacheDir . '/' . $baseFilename;
			if ($this->cacheExistsAt($userFolder, $cachePath)) {
				return $cachePath;
			}
		}
		
		return null;
	}

	/**
	 * Check if HLS cache exists for a video file
	 * Alternative method that uses settings to calculate cache path
	 * 
	 * @param mixed $userFolder The user's folder object
	 * @param string $filename The video filename
	 * @param string $directory The directory containing the video
	 * @param array $settings Settings containing locationType
	 * @return bool True if cache exists
	 */
	public function hasHlsCache($userFolder, string $filename, string $directory, array $settings): bool {
		$locationType = $settings['locationType'] ?? 'relative';
		$cacheBasePath = \OCA\HyperViewer\Service\PathResolver::calculateCachePath($locationType, $directory);
		
		// Cache folder is named after the video file
		$cachePath = $cacheBasePath . '/' . $filename;
		
		return $this->cacheExistsAt($userFolder, $cachePath);
	}
}

