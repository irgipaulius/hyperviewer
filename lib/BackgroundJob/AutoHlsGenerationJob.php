<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\HyperViewer\Service\FFmpegProcessManager;

class AutoHlsGenerationJob extends TimedJob {

	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private IConfig $config;
	private LoggerInterface $logger;
	private FFmpegProcessManager $processManager;
	private \OCA\HyperViewer\Service\CachedHlsDirectoryService $cachedHlsService;

	public function __construct(
		ITimeFactory $timeFactory,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IConfig $config,
		LoggerInterface $logger,
		FFmpegProcessManager $processManager,
		\OCA\HyperViewer\Service\CachedHlsDirectoryService $cachedHlsService
	) {
		parent::__construct($timeFactory);
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
		$this->processManager = $processManager;
		$this->cachedHlsService = $cachedHlsService;

		// Run every 10 minutes
		$this->setInterval(60 * 10);
	}

	protected function run($argument): void {
		try {
			// Refresh cache if needed (once per day)
			$this->refreshCacheIfNeeded();
			
			$autoGenDirs = $this->getAutoGenerationDirectories();
			
			foreach ($autoGenDirs as $settings) {
				$this->processDirectory($settings);
			}

		} catch (\Exception $e) {
			$this->logger->error('Auto HLS generation job failed', [
				'error' => $e->getMessage()
			]);
		}
	}

	private function getAutoGenerationDirectories(): array {
		$autoGenDirs = [];
		$allAppValues = $this->config->getAppKeys('hyperviewer');

		foreach ($allAppValues as $key) {
			if (strpos($key, 'auto_gen_') === 0) {
				$settingsJson = $this->config->getAppValue('hyperviewer', $key, '');
				if (!empty($settingsJson)) {
					$settings = json_decode($settingsJson, true);
					if ($settings && isset($settings['enabled']) && $settings['enabled']) {
						$autoGenDirs[] = $settings;
					}
				}
			}
		}

		return $autoGenDirs;
	}

	private function processDirectory(array $settings): void {
		$userId = $settings['userId'] ?? '';
		$directory = $settings['directory'] ?? '';

		if (empty($userId) || empty($directory)) return;

		$user = $this->userManager->get($userId);
		if (!$user) return;

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			
			if (!$userFolder->nodeExists($directory)) return;

			$dirNode = $userFolder->get($directory);
			if (!($dirNode instanceof \OCP\Files\Folder)) return;

			// Use find command for fast video discovery
			$this->findAndQueueVideos($dirNode, $directory, $userFolder, $userId, $settings);

			// Update lastScan timestamp efficiently
			$configKey = 'auto_gen_' . md5($userId . '_' . $directory);
			$json = $this->config->getAppValue('hyperviewer', $configKey, '');
			
			if (!empty($json)) {
				$data = json_decode($json, true);
				if ($data) {
					$data['lastScan'] = time();
					$this->config->setAppValue('hyperviewer', $configKey, json_encode($data));
				}
			}

		} catch (\Exception $e) {
			$this->logger->error('Failed to process auto-generation directory', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Find all video files using OS find command and queue them
	 * Much faster than recursive PHP loops
	 */
	private function findAndQueueVideos($folder, string $basePath, $userFolder, string $userId, array $settings): void {
		try {
			// Get local filesystem path
			$localPath = $folder->getStorage()->getLocalFile($folder->getInternalPath());
			
			if (!$localPath || !is_dir($localPath)) {
				$this->logger->warning('Could not get local path for auto-gen directory', ['path' => $basePath]);
				return;
			}

			// Use find to get all video files (much faster than PHP loops)
			$findCmd = sprintf(
				'find %s -type f \( -iname "*.mp4" -o -iname "*.mov" \) 2>/dev/null',
				escapeshellarg($localPath)
			);

			$output = shell_exec($findCmd);
			
			if ($output === null || trim($output) === '') {
				return;
			}

			// Parse output into video list
			$absolutePaths = array_filter(explode("\n", trim($output)));
			$videos = [];
			
			foreach ($absolutePaths as $absPath) {
				if (strpos($absPath, $localPath) !== 0) {
					continue;
				}
				
				$relPath = substr($absPath, strlen($localPath));
				$relPath = ltrim($relPath, '/');
				
				$filename = basename($absPath);
				$fileDir = dirname($relPath);
				
				// Build full directory path
				// If file is in the scanned directory root, use basePath
				// Otherwise, append the subdirectory to basePath
				if ($fileDir === '.') {
					$directory = $basePath;
				} else {
					// Combine basePath with subdirectory
					$directory = rtrim($basePath, '/') . '/' . $fileDir;
				}
				
				$videos[] = [
					'filename' => $filename,
					'directory' => $directory
				];
			}

			// Filter pipeline: files.filter(notQueued).filter(noCache)
			
			// Step 1: Filter out videos already in queue (except aborted)
			$notQueued = $this->processManager->filterNotQueued($videos, $userId);
			
			// Step 2: Filter out videos that already have HLS cache
			$needsProcessing = $this->filterNoCache($notQueued, $userFolder, $settings);
			
			// Step 3: Add remaining videos to queue
			foreach ($needsProcessing as $video) {
				$this->processManager->addJob($userId, $video['filename'], $video['directory'], $settings);
			}

		} catch (\Exception $e) {
			$this->logger->error('Failed to find and queue videos', [
				'path' => $basePath,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Filter out videos that already have HLS cache
	 */
	private function filterNoCache(array $videos, $userFolder, array $settings): array {
		return array_filter($videos, function($video) use ($userFolder, $settings) {
			// Return true if NO cache (needs processing)
			return !$this->hasHlsCache($userFolder, $video['filename'], $video['directory'], $settings);
		});
	}

	private function hasHlsCache($userFolder, string $filename, string $directory, array $settings): bool {
		return $this->cachedHlsService->hasHlsCache($userFolder, $filename, $directory, $settings);
	}

	/**
	 * Refresh cache for all users if needed (once per day)
	 */
	private function refreshCacheIfNeeded(): void {
		try {
			$this->userManager->callForAllUsers(function($user) {
				if ($this->cachedHlsService->shouldRefresh($user->getUID())) {
					$this->logger->info('Refreshing .cached_hls directory cache', ['userId' => $user->getUID()]);
					$this->cachedHlsService->refreshCache($user->getUID());
				}
			});
		} catch (\Exception $e) {
			$this->logger->error('Failed to refresh cache for users', ['error' => $e->getMessage()]);
		}
	}
}
