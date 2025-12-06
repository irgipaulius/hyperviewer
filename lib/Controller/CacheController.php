<?php

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use Psr\Log\LoggerInterface;


class CacheController extends Controller {
	
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private IJobList $jobList;
	private IConfig $config;
	private LoggerInterface $logger;
	private \OCA\HyperViewer\Service\FFmpegProcessManager $processManager;
	private \OCA\HyperViewer\Service\CachedHlsDirectoryService $cachedHlsService;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		IJobList $jobList,
		IConfig $config,
		LoggerInterface $logger,
		\OCA\HyperViewer\Service\FFmpegProcessManager $processManager,
		\OCA\HyperViewer\Service\CachedHlsDirectoryService $cachedHlsService
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->jobList = $jobList;
		$this->config = $config;
		$this->logger = $logger;
		$this->processManager = $processManager;
		$this->cachedHlsService = $cachedHlsService;
	}

	/**
	 * Generate HLS cache for video files
	 * 
	 * @NoAdminRequired
	 */
	public function generateCache(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$files = $this->request->getParam('files', []);
		$locationType = $this->request->getParam('locationType', 'relative');
		$overwriteExisting = $this->request->getParam('overwriteExisting', false);
		$resolutions = $this->request->getParam('resolutions', ['720p', '480p', '360p', '240p']);

		$jobIds = [];
		
		// Add background job for each file
		foreach ($files as $fileData) {
			// Check if cache already exists anywhere
			if (!$overwriteExisting) {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$existingCachePath = $this->cachedHlsService->findHlsCache(
					$userFolder, 
					$fileData['filename'], 
					$user->getUID()
				);
				
				if ($existingCachePath !== null) {
					// Cache exists somewhere, skip queuing
					continue;
				}
			}
			
			$directory = $fileData['directory'] ?? '';
			
			// Calculate cache path based on location type
			$cachePath = $this->calculateCachePath($locationType, $directory);
			
			$settings = [
				'cachePath' => $cachePath,
				'overwriteExisting' => $overwriteExisting,
				'resolutions' => $resolutions
			];
			
			$jobId = $this->processManager->addJob(
				$user->getUID(),
				$fileData['filename'],
				$directory,
				$settings
			);
			$jobIds[] = $jobId;
		}

		return new JSONResponse([
			'success' => true,
			'jobIds' => $jobIds,
			'message' => 'HLS cache generation started',
			'filesCount' => count($files)
		]);
	}

	/**
	 * Calculate cache path based on location type
	 * 
	 * @param string $locationType Either 'relative' or 'home'
	 * @param string $directory The directory containing the video file
	 * @return string The calculated cache path
	 */
	private function calculateCachePath(string $locationType, string $directory): string {
		if ($locationType === 'home') {
			// Home: cache at user root
			return '.cached_hls';
		}
		
		// Relative: cache in parent directory of the video
		// If directory is empty or root, use root level
		if (empty($directory) || $directory === '/') {
			return '.cached_hls';
		}
		
		// Get parent directory
		$parentDir = dirname($directory);
		if ($parentDir === '.' || $parentDir === '/') {
			return '.cached_hls';
		}
		
		return $parentDir . '/.cached_hls';
	}


	/**
	 * Get real-time progress for HLS generation
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getProgress(string $cachePath): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$decodedCachePath = urldecode($cachePath);
			
			// Check if cache directory exists
			if (!$userFolder->nodeExists($decodedCachePath)) {
				return new JSONResponse(['error' => 'Cache path not found'], 404);
			}

			$cacheFolder = $userFolder->get($decodedCachePath);
			if (!($cacheFolder instanceof \OCP\Files\Folder)) {
				return new JSONResponse(['error' => 'Invalid cache path'], 400);
			}

			// Look for progress.json file
			$progressFile = $decodedCachePath . '/progress.json';
			$logFile = $decodedCachePath . '/generation.log';

			$progressData = ['status' => 'not_found', 'progress' => 0];

			if ($userFolder->nodeExists($progressFile)) {
				$progressNode = $userFolder->get($progressFile);
				$progressContent = $progressNode->getContent();
				$progressData = json_decode($progressContent, true) ?: $progressData;
			}

			// Parse latest log entries for real-time progress if log exists
			if ($userFolder->nodeExists($logFile)) {
				$logNode = $userFolder->get($logFile);
				$logContent = $logNode->getContent();
				$parsedProgress = $this->parseFFmpegProgress($logContent);
				
				// Merge parsed progress with existing data
				$progressData = array_merge($progressData, $parsedProgress);
				$progressData['status'] = $progressData['completed'] ? 'completed' : 'processing';
			}

			return new JSONResponse([
				'success' => true,
				'progress' => $progressData
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to get progress', [
				'cachePath' => $cachePath,
				'error' => $e->getMessage()
			]);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Parse FFmpeg progress output from generation.log
	 */
	private function parseFFmpegProgress(string $logContent): array {
		$lines = explode("\n", $logContent);
		$progress = [
			'frame' => 0,
			'fps' => 0,
			'speed' => '0x',
			'time' => '00:00:00',
			'bitrate' => 'N/A',
			'size' => '0kB',
			'completed' => false,
			'lastUpdate' => time()
		];

		// Look for the latest progress line with frame info
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$line = trim($lines[$i]);
			
			// Check for completion first
			if (strpos($line, 'muxing overhead') !== false || 
				strpos($line, 'kb/s:') !== false) {
				$progress['completed'] = true;
				$progress['progress'] = 100;
				break;
			}
			
			// Parse progress lines like: frame=  847 fps= 24 q=-1.0 Lq=-1.0 q=-1.0 q=-1.0 q=-1.0 size=N/A time=00:00:35.30 bitrate=N/A speed=0.987x
			if (preg_match('/frame=\s*(\d+)/', $line, $frameMatch) && 
				preg_match('/fps=\s*([\d.]+)/', $line, $fpsMatch) &&
				preg_match('/time=(\d{2}:\d{2}:\d{2}\.\d+)/', $line, $timeMatch) &&
				preg_match('/speed=\s*([\d.]+x)/', $line, $speedMatch)) {
				
				$progress['frame'] = (int)$frameMatch[1];
				$progress['fps'] = (float)$fpsMatch[1];
				$progress['speed'] = $speedMatch[1];
				$progress['time'] = substr($timeMatch[1], 0, 8); // Trim to HH:MM:SS
				
				// Parse bitrate if available (not always N/A)
				if (preg_match('/bitrate=\s*([\d.]+kbits\/s)/', $line, $bitrateMatch)) {
					$progress['bitrate'] = $bitrateMatch[1];
				}
				
				// Parse size if available (not always N/A)
				if (preg_match('/size=\s*(\d+kB)/', $line, $sizeMatch)) {
					$progress['size'] = $sizeMatch[1];
				}
				
				break;
			}
		}

		return $progress;
	}

	/**
	 * Check if HLS cache exists for a video file
	 * 
	 * @NoAdminRequired
	 */
	public function checkCache(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$filename = $this->request->getParam('filename');
		$directory = $this->request->getParam('directory', '/');

		if (!$filename) {
			return new JSONResponse(['error' => 'Filename required'], 400);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$cacheExists = $this->findHlsCache($userFolder, $filename, $user->getUID());

		return new JSONResponse([
			'exists' => $cacheExists !== null,
			'cachePath' => $cacheExists,
			'filename' => $filename
		]);
	}


	/**
	 * Find HLS cache for a video file
	 * Uses cached directory locations for fast lookup
	 */
	private function findHlsCache($userFolder, string $filename, string $userId): ?string {
		return $this->cachedHlsService->findHlsCache($userFolder, $filename, $userId);
	}

	/**
	 * Discover video files recursively in a directory
	 * 
	 * @NoAdminRequired
	 */
	public function discoverVideos(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$directory = $this->request->getParam('directory');
		if (!$directory) {
			return new JSONResponse(['error' => 'Directory path required'], 400);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$videoFiles = $this->scanDirectoryForVideos($userFolder, $directory);


			return new JSONResponse([
				'success' => true,
				'files' => $videoFiles,
				'directory' => $directory
			]);

		} catch (\Exception $e) {
			$this->logger->error('Video discovery failed', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Register directory for automatic HLS generation
	 * 
	 * @NoAdminRequired
	 */
	public function registerAutoGeneration(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$directory = $this->request->getParam('directory');
		$options = $this->request->getParam('options', []);

		if (!$directory) {
			return new JSONResponse(['error' => 'Directory path required'], 400);
		}

		try {
			// Calculate cache path based on location type
			$locationType = $options['locationType'] ?? 'relative';
			$cachePath = $this->calculateCachePath($locationType, $directory);
			
			// Store auto-generation settings in app config
			$autoGenSettings = [
				'userId' => $user->getUID(),
				'directory' => $directory,
				'cachePath' => $cachePath,
				'locationType' => $locationType,
				'overwriteExisting' => $options['overwriteExisting'] ?? false,
				'resolutions' => $options['resolutions'] ?? ['720p', '480p', '360p', '240p'],
				'enabled' => true,
				'createdAt' => time()
			];

			// Use a simple key-value storage for now (could be moved to database later)
			$configKey = 'auto_gen_' . md5($user->getUID() . '_' . $directory);
			\OC::$server->getConfig()->setAppValue('hyperviewer', $configKey, json_encode($autoGenSettings));


			return new JSONResponse([
				'success' => true,
				'message' => 'Directory registered for auto-generation',
				'directory' => $directory
			]);

		} catch (\Exception $e) {
			$this->logger->error('Auto-generation registration failed', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Recursively scan directory for video files
	 */
	private function scanDirectoryForVideos($userFolder, string $directoryPath): array {
		$videoFiles = [];
		$supportedExtensions = ['.mp4', '.MP4', '.mov', '.MOV'];

		try {
			if (!$userFolder->nodeExists($directoryPath)) {
				throw new \Exception("Directory not found: $directoryPath");
			}

			$directory = $userFolder->get($directoryPath);
			if (!($directory instanceof \OCP\Files\Folder)) {
				throw new \Exception("Path is not a directory: $directoryPath");
			}

			$this->scanFolderRecursively($directory, $directoryPath, $supportedExtensions, $videoFiles);

		} catch (\Exception $e) {
			$this->logger->error('Directory scanning failed', [
				'directory' => $directoryPath,
				'error' => $e->getMessage()
			]);
			throw $e;
		}

		return $videoFiles;
	}

	/**
	 * Recursively scan folder for video files (optimized with extension matching)
	 */
	private function scanFolderRecursively($folder, string $basePath, array $supportedExtensions, array &$videoFiles): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\File) {
				$filename = $node->getName();
				
				// Fast extension check - no MIME type lookup needed
				$hasVideoExtension = false;
				foreach ($supportedExtensions as $ext) {
					if (substr($filename, -strlen($ext)) === $ext) {
						$hasVideoExtension = true;
						break;
					}
				}
				
				if ($hasVideoExtension) {
					$relativePath = $basePath === '/' ? '/' : $basePath;
					$videoFiles[] = [
						'filename' => $filename,
						'directory' => $relativePath,
						'size' => $node->getSize(),
						'fullPath' => $relativePath . '/' . $filename
					];
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				// Skip hidden directories and cache directories
				$folderName = $node->getName();
				if (strpos($folderName, '.') !== 0) {
					$subPath = $basePath === '/' ? '/' . $folderName : $basePath . '/' . $folderName;
					$this->scanFolderRecursively($node, $subPath, $supportedExtensions, $videoFiles);
				}
			}
		}
	}

	/**
	 * Serve HLS files (playlist.m3u8 and segments)
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function serveHlsFile(string $cachePath, string $filename): Response {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new Response('Unauthorized', 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			
			// Decode the cache path (it might be URL encoded)
			$decodedCachePath = urldecode($cachePath);
			$decodedFilename = urldecode($filename);
			
			// Construct the full path to the HLS file
			$fullPath = $decodedCachePath . '/' . $decodedFilename;
			

			// Check if the file exists
			if (!$userFolder->nodeExists($fullPath)) {
			}

			$file = $userFolder->get($fullPath);
			
			if (!$file instanceof \OCP\Files\File) {
				return new Response('Not a file', 400);
			}

			// Determine content type based on file extension
			$contentType = 'application/octet-stream';
			$extension = pathinfo($decodedFilename, PATHINFO_EXTENSION);
			
			switch (strtolower($extension)) {
				case 'm3u8':
					$contentType = 'application/vnd.apple.mpegurl';
					break;
				case 'ts':
					$contentType = 'video/mp2t';
					break;
				case 'mp4':
					$contentType = 'video/mp4';
					break;
			}

			// Create stream response
			$response = new StreamResponse($file->fopen('r'));
			$response->addHeader('Content-Type', $contentType);
			$response->addHeader('Content-Length', (string)$file->getSize());
			
			// Add CORS headers for HLS playback
			$response->addHeader('Access-Control-Allow-Origin', '*');
			$response->addHeader('Access-Control-Allow-Methods', 'GET');
			$response->addHeader('Access-Control-Allow-Headers', 'Range');
			
			// Add caching headers for segments
			if ($extension === 'ts' || $extension === 'mp4') {
				$response->addHeader('Cache-Control', 'public, max-age=31536000'); // 1 year
			} else {
				$response->addHeader('Cache-Control', 'public, max-age=300'); // 5 minutes for playlists
			}

			return $response;

		} catch (\Exception $e) {
			$this->logger->error('Error serving HLS file', [
				'error' => $e->getMessage(),
				'cachePath' => $cachePath,
				'filename' => $filename
			]);
			return new Response('Internal server error', 500);
		}
	}

	/**
	 * Get all active HLS generation jobs with progress
	 * 
	 * @NoAdminRequired
	 */
	/**
	 * Get jobs with pagination support
	 * Returns raw queue data for current user
	 * 
	 * @NoAdminRequired
	 */
	public function getActiveJobs(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		try {
			$lastId = $this->request->getParam('lastId', '');
			$limit = min((int)$this->request->getParam('limit', 10), 1000); // Max 1000 items
			
			$allJobs = $this->processManager->getQueue();
			$userJobs = [];
			$foundLastId = empty($lastId); // If no lastId, start from beginning
			
			// Filter for current user and paginate
			foreach ($allJobs as $job) {
				if ($job['userId'] !== $user->getUID()) {
					continue;
				}
				
				// Skip until we find lastId
				if (!$foundLastId) {
					if ($job['id'] === $lastId) {
						$foundLastId = true;
					}
					continue;
				}
				
				$userJobs[] = $job;
				
				// Stop when we reach the limit
				if (count($userJobs) >= $limit) {
					break;
				}
			}

			return new JSONResponse([
				'jobs' => $userJobs,
				'hasMore' => count($userJobs) === $limit
			]);

		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to get jobs'], 500);
		}
	}



	/**
	 * Get detailed progress for a specific active job
	 * 
	 * @NoAdminRequired
	 */
	public function getJobProgress(string $filename): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		try {
			$decodedFilename = urldecode($filename);
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			
			$targetCachePath = null;

			// 1. Check active jobs first to find the directory
			$activeJobs = $this->processManager->getActiveJobs();
			foreach ($activeJobs as $job) {
				if ($job['filename'] === $decodedFilename && $job['userId'] === $user->getUID()) {
					// Found the job, calculate cache path
					$locationType = $job['settings']['locationType'] ?? 'relative';
					$directory = $job['directory'];
					$targetCachePath = $this->calculateCachePath($locationType, $directory);
					break;
				}
			}

			// 2. If not found in active jobs, check Home cache (most likely default)
			if (!$targetCachePath) {
				$targetCachePath = '.cached_hls';
			}

			// 3. Try to read progress from the determined path
			$locationsToCheck = [$targetCachePath];
			if ($targetCachePath !== '.cached_hls') {
				// If we found a specific relative path, check that AND home as fallback
				$locationsToCheck[] = '.cached_hls';
			}

			foreach ($locationsToCheck as $basePath) {
				$resolvedPath = $basePath;
				if ($userFolder->nodeExists($resolvedPath)) {
					$cacheFolder = $userFolder->get($resolvedPath);
					if ($cacheFolder instanceof \OCP\Files\Folder) {
						// Try to find the specific job directory
						// First try exact match
						if ($cacheFolder->nodeExists($decodedFilename)) {
							$jobFolder = $cacheFolder->get($decodedFilename);
						} else {
							// Fallback: try with extension removed
							$jobDirName = pathinfo($decodedFilename, PATHINFO_FILENAME);
							if ($cacheFolder->nodeExists($jobDirName)) {
								$jobFolder = $cacheFolder->get($jobDirName);
							} else {
								continue;
							}
						}

						if (isset($jobFolder) && $jobFolder instanceof \OCP\Files\Folder && $jobFolder->nodeExists('progress.json')) {
							try {
								$progressFile = $jobFolder->get('progress.json');
								$content = $progressFile->getContent();
								$data = json_decode($content, true);
								if ($data) {
									// Enrich with cache directory size if processing
									$cacheSize = 0;
									if (($data['status'] ?? '') === 'processing') {
										$stats = $this->getDirectoryStats($userFolder, $jobFolder->getPath());
										$cacheSize = $stats['size'];
									}
									
									return new JSONResponse([
										'cachePath' => $jobFolder->getPath(),
										'filename' => $decodedFilename,
										'progress' => $data['progress'] ?? 0,
										'status' => $data['status'] ?? 'unknown',
										'frame' => $data['frame'] ?? 0,
										'fps' => $data['fps'] ?? 0,
										'speed' => $data['speed'] ?? '0x',
										'time' => $data['time'] ?? '00:00:00',
										'bitrate' => $data['bitrate'] ?? '0kbits/s',
										'size' => $data['size'] ?? '0kB',
										'cacheSize' => $cacheSize,
										'resolutions' => $data['resolutions'] ?? [],
										'startTime' => $data['startTime'] ?? time(),
										'lastUpdate' => $data['lastUpdate'] ?? time()
									]);
								}
							} catch (\Exception $e) {
								// Skip invalid progress files
								continue;
							}
						}
					}
				}
			}

			// Debug info: return what we searched for
			$debugInfo = [
				'error' => 'Job not found or not active',
				'debug' => [
					'originalFilename' => $filename,
					'decodedFilename' => $decodedFilename,
					'searchedLocations' => $locationsToCheck,
					'userId' => $user->getUID()
				]
			];
			
			return new JSONResponse($debugInfo, 404);

		} catch (\Exception $e) {
			$this->logger->error('Error getting job progress', ['error' => $e->getMessage(), 'filename' => $filename]);
			return new JSONResponse(['error' => 'Failed to get job progress'], 500);
		}
	}

	/**
	 * Get auto-generation directory settings
	 * 
	 * @NoAdminRequired
	 */
	public function getAutoGenerationSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$autoGenDirs = [];
			$allAppValues = $this->config->getAppKeys('hyperviewer');

			foreach ($allAppValues as $key) {
				if (strpos($key, 'auto_gen_') === 0) {
					$settingsJson = $this->config->getAppValue('hyperviewer', $key, '');
					if (!empty($settingsJson)) {
						$settings = json_decode($settingsJson, true);
						if ($settings && isset($settings['userId']) && $settings['userId'] === $user->getUID()) {
							$dirPath = $settings['directory'] ?? '';
							$cacheSize = 0;
							
							// Resolving cache directory path
							$checkPath = $settings['cachePath'] ?? '';
							if (empty($checkPath) && !empty($dirPath)) {
								$checkPath = $dirPath . '/.cached_hls';
							}

							$stats = $this->getDirectoryStats($userFolder, $checkPath);
							$cacheSize = $stats['size'];
							$cacheCount = $stats['count'];

							$autoGenDirs[] = [
								'configKey' => $key,
								'directory' => $dirPath,
								'enabled' => $settings['enabled'] ?? false,
								'resolutions' => $settings['resolutions'] ?? [],
								'cachePath' => $settings['cachePath'] ?? '',
								'registeredAt' => $settings['registeredAt'] ?? $settings['createdAt'] ?? time(),
								'lastScan' => $settings['lastScan'] ?? 0,
								'totalCacheSize' => $this->formatBytes($cacheSize),
								'videosCount' => $cacheCount,
							];
						}
					}
				}
			}

			return new JSONResponse(['autoGenDirs' => $autoGenDirs]);

		} catch (\Exception $e) {
			$this->logger->error('Error getting auto-generation settings', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to get settings'], 500);
		}
	}

	/**
	 * Remove auto-generation for a directory
	 * 
	 * @NoAdminRequired
	 */
	public function removeAutoGeneration(string $configKey): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		try {
			// Verify the config belongs to this user
			$settingsJson = $this->config->getAppValue('hyperviewer', $configKey, '');
			if (empty($settingsJson)) {
				return new JSONResponse(['error' => 'Configuration not found'], 404);
			}

			$settings = json_decode($settingsJson, true);
			if (!$settings || ($settings['userId'] ?? '') !== $user->getUID()) {
				return new JSONResponse(['error' => 'Unauthorized'], 403);
			}

			// Remove the configuration
			$this->config->deleteAppValue('hyperviewer', $configKey);


			return new JSONResponse(['success' => true]);

		} catch (\Exception $e) {
			$this->logger->error('Error removing auto-generation', [
				'error' => $e->getMessage(),
				'configKey' => $configKey
			]);
			return new JSONResponse(['error' => 'Failed to remove auto-generation'], 500);
		}
	}

	/**
	 * Get job statistics and history
	 * 
	 * @NoAdminRequired
	 */
	public function getJobStatistics(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			
			$stats = [
				'completedJobs' => 0,
				'pendingJobs' => 0,
				'activeJobs' => 0,
				'totalCacheSize' => 0,
			];

			// Get all cached .cached_hls directories
			$cachedDirs = $this->cachedHlsService->getCachedDirectories($user->getUID());
			
			// 1. Calculate Total Cache Size efficiently
			$totalSize = 0;
			$completedCount = 0;

			foreach ($cachedDirs as $cacheDirPath) {
				$dirStats = $this->getDirectoryStats($userFolder, $cacheDirPath);
				$totalSize += $dirStats['size'];
				$completedCount += $dirStats['count'];
			}

			$stats['totalCacheSize'] = $this->formatBytes($totalSize);
			$stats['completedJobs'] = $completedCount;

			// 2. Get accurate active/pending job stats from ProcessManager
			$managerStats = $this->processManager->getJobStatistics();
			$stats['activeJobs'] = $managerStats['active'];
			$stats['pendingJobs'] = $managerStats['pending'];
			
			return new JSONResponse(['stats' => $stats]);

		} catch (\Exception $e) {
			$this->logger->error('Error getting job statistics', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to get statistics'], 500);
		}
	}

	private function getDirectoryStats($userFolder, string $path): array {
		try {
			if (empty($path) || !$userFolder->nodeExists($path)) {
				return ['size' => 0, 'count' => 0];
			}

			$node = $userFolder->get($path);
			if (!($node instanceof \OCP\Files\Folder)) {
				return ['size' => 0, 'count' => 0];
			}

			$localPath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if (!$localPath || !is_dir($localPath)) {
				return ['size' => 0, 'count' => 0];
			}

			$escapedPath = escapeshellarg($localPath);
			$size = 0;
			$count = 0;

			// Size
			$duOutput = shell_exec('du -s ' . $escapedPath);
			if ($duOutput) {
				$parts = preg_split('/\s+/', trim($duOutput));
				if (isset($parts[0]) && is_numeric($parts[0])) {
					$size = (int)$parts[0] * 1024;
				}
			}

			// Count
			$findCmd = 'find ' . $escapedPath . ' -mindepth 1 -maxdepth 1 -type d | wc -l';
			$findOutput = shell_exec($findCmd);
			if ($findOutput) {
				$count = (int)trim($findOutput);
			}

			return ['size' => $size, 'count' => $count];

		} catch (\Exception $e) {
			return ['size' => 0, 'count' => 0];
		}
	}



	/**
	 * Format bytes into human readable format
	 */
	private function formatBytes(int $size): string {
		if ($size >= 1024 * 1024 * 1024) {
			return round($size / (1024 * 1024 * 1024), 1) . ' GB';
		} elseif ($size >= 1024 * 1024) {
			return round($size / (1024 * 1024), 1) . ' MB';
		} elseif ($size >= 1024) {
			return round($size / 1024, 1) . ' KB';
		} else {
			return $size . ' B';
		}
	}

	/**
	 * Batch check HLS cache for multiple videos in a directory
	 * Much faster than checking each file individually
	 * 
	 * @NoAdminRequired
	 */
	public function batchCheckCache(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$directory = $this->request->getParam('directory', '/');
		$filenames = $this->request->getParam('filenames', []);

		if (empty($filenames)) {
			return new JSONResponse(['cachedVideos' => []]);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$cachedVideos = [];

		// Check each video file
		foreach ($filenames as $filename) {
			$cachePath = $this->findHlsCache($userFolder, $filename, $user->getUID());
			if ($cachePath !== null) {
				$cachedVideos[] = $filename;
			}
		}

		return new JSONResponse([
			'directory' => $directory,
			'cachedVideos' => $cachedVideos,
			'totalChecked' => count($filenames)
		]);
	}

	private function resolveCachePath(string $path, $userFolder): string {
		return \OCA\HyperViewer\Service\PathResolver::resolveCachePath($path);
	}

	/**
	 * Extract a frame from the original video file at a specific timestamp
	 * 
	 * @NoAdminRequired
	 */
	public function extractFrame(): Response {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		$filename = $this->request->getParam('filename');
		$directory = $this->request->getParam('directory', '/');
		$timestamp = (float)$this->request->getParam('timestamp', 0);

		if (!$filename) {
			return new JSONResponse(['error' => 'Missing filename'], 400);
		}

		try {
			$t1 = microtime(true);
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$t2 = microtime(true);
			
			$targetDir = $directory === '/' ? $userFolder : $userFolder->get(ltrim($directory, '/'));
			$t3 = microtime(true);
			
			$videoFile = $targetDir->get($filename);
			$t4 = microtime(true);
			
			$filePath = $videoFile->getStorage()->getLocalFile($videoFile->getInternalPath());
			$t5 = microtime(true);

			$tempFile = sys_get_temp_dir() . '/hyperviewer_frame_' . uniqid() . '.jpg';
			$cmd = sprintf(
				'/usr/local/bin/ffmpeg -ss %F -i %s -frames:v 1 -q:v 1 %s 2>&1',
				$timestamp,
				escapeshellarg($filePath),
				escapeshellarg($tempFile)
			);

			$t6 = microtime(true);
			exec($cmd, $output, $status);
			$t7 = microtime(true);

			if ($status !== 0 || !file_exists($tempFile)) {
				return new JSONResponse(['error' => 'FFmpeg failed: ' . implode(' ', $output)], 500);
			}

			$t8 = microtime(true);
				$response = new StreamResponse(fopen($tempFile, 'r'));
			$t9 = microtime(true);
			
			$response->addHeader('Content-Type', 'image/jpeg');
			
			register_shutdown_function(function() use ($tempFile) {
				if (file_exists($tempFile)) {
					@unlink($tempFile);
				}
			});
			
			return $response;

		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}


	/**
	 * Delete multiple jobs
	 * 
	 * @NoAdminRequired
	 */
	public function batchDeleteJobs(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		$ids = $this->request->getParam('ids', []);
		if (!is_array($ids) || empty($ids)) {
			return new JSONResponse(['error' => 'No IDs provided'], 400);
		}

		$deletedCount = 0;
		$errors = [];

		foreach ($ids as $id) {
			try {
				if ($this->processManager->deleteJob($id, $user->getUID())) {
					$deletedCount++;
				} else {
					$errors[$id] = 'Job not found or access denied';
				}
			} catch (\Exception $e) {
				$errors[$id] = $e->getMessage();
			}
		}

		return new JSONResponse([
			'success' => true,
			'deleted' => $deletedCount,
			'errors' => $errors
		]);
	}

	/**
	 * Get status for multiple jobs
	 * 
	 * @NoAdminRequired
	 */
	public function batchGetJobStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		$ids = $this->request->getParam('ids', []);
		if (!is_array($ids) || empty($ids)) {
			return new JSONResponse(['error' => 'No IDs provided'], 400);
		}

		$jobs = [];
		foreach ($ids as $id) {
			$job = $this->processManager->getJob($id);
			if ($job && $job['userId'] === $user->getUID()) {
				$jobs[] = $job;
			}
		}

		return new JSONResponse(['jobs' => $jobs]);
	}
}
