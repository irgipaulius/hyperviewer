<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;

class AutoHlsGenerationJob extends TimedJob {

	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private IConfig $config;
	private IJobList $jobList;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $timeFactory,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IConfig $config,
		IJobList $jobList,
		LoggerInterface $logger
	) {
		parent::__construct($timeFactory);
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->logger = $logger;

		$this->setInterval(60 * 10);
	}

	protected function run($argument): void {
		// Check for job lock to prevent concurrent execution
		$lockFile = '/tmp/hyper_hls_autogen.lock';
		if (!$this->acquireJobLock($lockFile)) {
			return;
		}

		try {
			// Get all registered auto-generation directories
			$autoGenDirs = $this->getAutoGenerationDirectories();
			
			if (empty($autoGenDirs)) {
				return;
			}

			foreach ($autoGenDirs as $configKey => $settings) {
				$this->processAutoGenerationDirectory($configKey, $settings);
			}

		} catch (\Exception $e) {
			$this->logger->error('Auto HLS generation job failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		} finally {
			// Always release the lock
			$this->releaseJobLock($lockFile);
		}
	}

	/**
	 * Get all registered auto-generation directories
	 */
	private function getAutoGenerationDirectories(): array {
		$autoGenDirs = [];
		$allAppValues = $this->config->getAppKeys('hyperviewer');

		foreach ($allAppValues as $key) {
			if (strpos($key, 'auto_gen_') === 0) {
				$settingsJson = $this->config->getAppValue('hyperviewer', $key, '');
				if (!empty($settingsJson)) {
					$settings = json_decode($settingsJson, true);
					if ($settings && isset($settings['enabled']) && $settings['enabled']) {
						$autoGenDirs[$key] = $settings;
					}
				}
			}
		}

		return $autoGenDirs;
	}

	/**
	 * Process a single auto-generation directory
	 */
	private function processAutoGenerationDirectory(string $configKey, array $settings): void {
		$userId = $settings['userId'] ?? '';
		$directory = $settings['directory'] ?? '';

		if (empty($userId) || empty($directory)) {
			return;
		}

		$user = $this->userManager->get($userId);
		if (!$user) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			
			// Check if directory still exists
			if (!$userFolder->nodeExists($directory)) {
				$this->disableAutoGeneration($configKey);
				return;
			}

			// Scan for new video files that don't have HLS cache
			$newVideoFiles = $this->findNewVideoFiles($userFolder, $directory, $settings);

			if (empty($newVideoFiles)) {
				return;
			}

			// Generate HLS cache for new files
			$this->queueHlsGenerationJobs($newVideoFiles, $settings);

		} catch (\Exception $e) {
			$this->logger->error('Failed to process auto-generation directory', [
				'directory' => $directory,
				'userId' => $userId,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Find video files that don't have HLS cache yet
	 */
	private function findNewVideoFiles($userFolder, string $directory, array $settings): array {
		$newFiles = [];
		$supportedMimes = ['video/quicktime', 'video/mp4'];
		$userId = $settings['userId'];

		try {
			$dirNode = $userFolder->get($directory);
			if (!($dirNode instanceof \OCP\Files\Folder)) {
				return [];
			}

			$this->scanForNewVideos($dirNode, $directory, $supportedMimes, $userFolder, $userId, $newFiles);

		} catch (\Exception $e) {
			$this->logger->error('Failed to scan for new videos', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
		}

		return $newFiles;
	}

	/**
	 * Recursively scan for new video files without HLS cache
	 */
	private function scanForNewVideos($folder, string $basePath, array $supportedMimes, $userFolder, string $userId, array &$newFiles): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\File) {
				$mimeType = $node->getMimeType();
				if (in_array($mimeType, $supportedMimes)) {
					// Check if HLS cache already exists
					if (!$this->hasHlsCache($userFolder, $node->getName(), $basePath, $userId)) {
						$relativePath = $basePath === '/' ? '/' : $basePath;
						$newFiles[] = [
							'filename' => $node->getName(),
							'directory' => $relativePath,
							'size' => $node->getSize(),
							'mimeType' => $mimeType,
							'modifiedTime' => $node->getMTime()
						];
					}
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				// Skip hidden directories and cache directories
				$folderName = $node->getName();
				if (strpos($folderName, '.') !== 0) {
					$subPath = $basePath === '/' ? '/' . $folderName : $basePath . '/' . $folderName;
					$this->scanForNewVideos($node, $subPath, $supportedMimes, $userFolder, $userId, $newFiles);
				}
			}
		}
	}

	/**
	 * Check if HLS cache exists for a video file
	 */
	private function hasHlsCache($userFolder, string $filename, string $directory, string $userId): bool {
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		
		// Get user's cache locations (uses PersonalSettings defaults)
		$userCacheLocations = json_decode(
			$this->config->getUserValue(
				$userId,
				'hyperviewer',
				'cache_locations',
				json_encode(\OCA\HyperViewer\Settings\PersonalSettings::getDefaultCacheLocations())
			),
			true
		);

		// Resolve each location to actual paths
		$cacheLocations = [];
		foreach ($userCacheLocations as $location) {
			$location = rtrim($location, '/');
			
			// Handle different path formats
			if ($location === '.' || str_starts_with($location, './')) {
				$cacheLocations[] = $directory . '/.cached_hls/' . $baseFilename;
			} elseif ($location === '~' || str_starts_with($location, '~/')) {
				$cacheLocations[] = '/.cached_hls/' . $baseFilename;
			} elseif (str_starts_with($location, '/')) {
				$cacheLocations[] = $location . '/' . $baseFilename;
			} else {
				$cacheLocations[] = '/' . $location . '/' . $baseFilename;
			}
		}

		foreach ($cacheLocations as $cachePath) {
			try {
				// Check for adaptive streaming master playlist first, fallback to single playlist
				if ($userFolder->nodeExists($cachePath . '/master.m3u8') || 
					$userFolder->nodeExists($cachePath . '/playlist.m3u8')) {
					return true;
				}
			} catch (\Exception $e) {
				// Continue checking other locations
				continue;
			}
		}

		return false;
	}

	/**
	 * Queue HLS generation jobs for new video files
	 */
	private function queueHlsGenerationJobs(array $videoFiles, array $settings): void {
		$jobId = uniqid('auto_hls_', true);

		foreach ($videoFiles as $fileData) {
			$jobData = [
				'jobId' => $jobId,
				'userId' => $settings['userId'],
				'filename' => $fileData['filename'],
				'directory' => $fileData['directory'],
				'cachePath' => $settings['cachePath'] ?? '',
				'overwriteExisting' => $settings['overwriteExisting'] ?? false,
				'resolutions' => $settings['resolutions'] ?? ['720p', '480p', '240p'],
				'autoGenerated' => true
			];


			$this->jobList->add(HlsCacheGenerationJob::class, $jobData);
		}
	}

	/**
	 * Disable auto-generation for a directory (e.g., if directory no longer exists)
	 */
	private function disableAutoGeneration(string $configKey): void {
		$settingsJson = $this->config->getAppValue('hyperviewer', $configKey, '');
		if (!empty($settingsJson)) {
			$settings = json_decode($settingsJson, true);
			if ($settings) {
				$settings['enabled'] = false;
				$settings['disabledAt'] = time();
				$this->config->setAppValue('hyperviewer', $configKey, json_encode($settings));
			}
		}
	}

	/**
	 * Acquire job lock to prevent concurrent execution
	 */
	private function acquireJobLock(string $lockFile): bool {
		// Check if lock file exists and is recent (within last hour)
		if (file_exists($lockFile)) {
			$lockTime = filemtime($lockFile);
			$currentTime = time();
			
			// If lock is less than 1 hour old, skip execution
			if (($currentTime - $lockTime) < 3600) {
				return false;
			}
			
			// Lock is stale, remove it
			unlink($lockFile);
		}

		// Create lock file with current timestamp
		$success = file_put_contents($lockFile, json_encode([
			'pid' => getmypid(),
			'startTime' => time(),
			'hostname' => gethostname()
		]));

		if ($success === false) {
			$this->logger->error('Failed to create auto-generation lock file', ['lockFile' => $lockFile]);
			return false;
		}

		return true;
	}

	/**
	 * Release job lock
	 */
	private function releaseJobLock(string $lockFile): void {
		if (file_exists($lockFile)) {
			unlink($lockFile);
		}
	}
}
