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

	public function __construct(
		ITimeFactory $timeFactory,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IConfig $config,
		LoggerInterface $logger,
		FFmpegProcessManager $processManager
	) {
		parent::__construct($timeFactory);
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
		$this->processManager = $processManager;

		// Run every 10 minutes
		$this->setInterval(60 * 10);
	}

	protected function run($argument): void {
		try {
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
		$supportedMimes = ['video/quicktime', 'video/mp4'];

		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\File) {
				if (in_array($node->getMimeType(), $supportedMimes)) {
					if (!$this->hasHlsCache($userFolder, $node->getName(), $basePath, $userId)) {
						// Normalize directory path - convert '/' to empty string for root
						$normalizedDir = ($basePath === '/' || $basePath === '') ? '' : $basePath;
						
						// Add to queue via ProcessManager
						$this->processManager->addJob(
							$userId,
							$node->getName(),
							$normalizedDir,
							$settings
						);
					}
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				$folderName = $node->getName();
				if (strpos($folderName, '.') !== 0) {
					$subPath = $basePath === '/' ? '/' . $folderName : $basePath . '/' . $folderName;
					$this->scanAndQueue($node, $subPath, $userFolder, $userId, $settings);
				}
			}
		}
	}

	private function hasHlsCache($userFolder, string $filename, string $directory, string $userId): bool {
		// Simplified check - check default location or configured location
		// For now, assuming standard cache structure
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		// This logic needs to match where we actually put the cache
		// In ProcessManager we put it in $settings['cachePath']
		
		// We can't easily know the exact cache path here without duplicating logic
		// But we can check if the job is already in the queue (ProcessManager handles duplicates)
		// So we mainly need to check if the *file* exists on disk
		
		// TODO: Better cache existence check
		return false; 
	}
}
