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

			$this->scanAndQueue($dirNode, $directory, $userFolder, $userId, $settings);

		} catch (\Exception $e) {
			$this->logger->error('Failed to process auto-generation directory', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
		}
	}

	private function scanAndQueue($folder, string $basePath, $userFolder, string $userId, array $settings): void {
		$supportedExtensions = ['.mp4', '.MP4', '.mov', '.MOV'];
		
		// Use iterative queue-based traversal
		$queue = [['folder' => $folder, 'path' => $basePath]];
		
		while (!empty($queue)) {
			$current = array_shift($queue);
			$currentFolder = $current['folder'];
			$currentPath = $current['path'];

			foreach ($currentFolder->getDirectoryListing() as $node) {
				if ($node instanceof \OCP\Files\File) {
					$filename = $node->getName();
					
					// Fast extension check
					$hasVideoExtension = false;
					foreach ($supportedExtensions as $ext) {
						if (substr($filename, -strlen($ext)) === $ext) {
							$hasVideoExtension = true;
							break;
						}
					}
					
					if ($hasVideoExtension) {
						if (!$this->hasHlsCache($userFolder, $filename, $currentPath, $settings)) {
							// Normalize directory path - convert '/' to empty string for root
							$normalizedDir = ($currentPath === '/' || $currentPath === '') ? '' : $currentPath;
							
							// Add to queue via ProcessManager
							$this->processManager->addJob(
								$userId,
								$filename,
								$normalizedDir,
								$settings
							);
						}
					}
				} elseif ($node instanceof \OCP\Files\Folder) {
					$folderName = $node->getName();
					if (strpos($folderName, '.') !== 0) {
						$subPath = $currentPath === '/' ? '/' . $folderName : $currentPath . '/' . $folderName;
						$queue[] = ['folder' => $node, 'path' => $subPath];
					}
				}
			}
		}
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
