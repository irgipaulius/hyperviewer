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
use OCA\HyperViewer\BackgroundJob\HlsCacheGenerationJob;

class CacheController extends Controller {
	
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private IJobList $jobList;
	private IConfig $config;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		IJobList $jobList,
		IConfig $config,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->jobList = $jobList;
		$this->config = $config;
		$this->logger = $logger;
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
		$cachePath = $this->request->getParam('cachePath', '');
		$overwriteExisting = $this->request->getParam('overwriteExisting', false);
		$resolutions = $this->request->getParam('resolutions', ['720p', '480p', '240p']);

		if (empty($cachePath)) {
			return new JSONResponse(['error' => 'Cache path is required'], 400);
		}


		$jobId = uniqid('hls_cache_', true);
		
		// Add background job for each file
		foreach ($files as $fileData) {
			$jobData = [
				'jobId' => $jobId,
				'userId' => $user->getUID(),
				'filename' => $fileData['filename'],
				'directory' => $fileData['directory'] ?? '/',
				'cachePath' => $cachePath,
				'overwriteExisting' => $overwriteExisting,
				'resolutions' => $resolutions
			];
			
			
			$this->jobList->add(HlsCacheGenerationJob::class, $jobData);
		}
		return new JSONResponse([
			'success' => true,
			'jobId' => $jobId,
			'message' => 'HLS cache generation started',
			'filesCount' => count($files)
		]);
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
		$cacheExists = $this->findHlsCache($userFolder, $filename, $directory, $user->getUID());

		return new JSONResponse([
			'exists' => $cacheExists !== null,
			'cachePath' => $cacheExists,
			'filename' => $filename
		]);
	}

	/**
	 * Get cache locations from user settings
	 */
	/**
	 * Get user's cache locations from settings
	 * Uses PersonalSettings::getDefaultCacheLocations() as default
	 * NO fallbacks - if JSON is invalid, throw error
	 */
	private function getUserCacheLocations(string $userId): array {
		$cacheLocationsJson = $this->config->getUserValue(
			$userId,
			$this->appName,
			'cache_locations',
			json_encode(\OCA\HyperViewer\Settings\PersonalSettings::getDefaultCacheLocations())
		);

		$locations = json_decode($cacheLocationsJson, true);
		if (!is_array($locations)) {
			throw new \Exception('Invalid cache_locations configuration');
		}

		return $locations;
	}

	/**
	 * Find HLS cache for a video file
	 */
	private function findHlsCache($userFolder, string $filename, string $directory, string $userId): ?string {
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		
		// Get user's cache locations (uses PersonalSettings defaults)
		$userCacheLocations = $this->getUserCacheLocations($userId);
		
		// Resolve each location to actual paths
		$cacheLocations = [];
		foreach ($userCacheLocations as $location) {
			$resolvedPath = $this->resolveCachePath($location . '/' . $baseFilename, $userFolder);
			$cacheLocations[] = $resolvedPath;
		}

		// Check each location for HLS files
		foreach ($cacheLocations as $cachePath) {
			try {
				if ($userFolder->nodeExists($cachePath . '/master.m3u8')) {
					return $cachePath;
				} elseif ($userFolder->nodeExists($cachePath . '/playlist.m3u8')) {
					return $cachePath;
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		return null;
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

		if (empty($options['cachePath'])) {
			return new JSONResponse(['error' => 'Cache path is required'], 400);
		}

		try {
			// Store auto-generation settings in app config
			$autoGenSettings = [
				'userId' => $user->getUID(),
				'directory' => $directory,
				'cachePath' => $options['cachePath'],
				'overwriteExisting' => $options['overwriteExisting'] ?? false,
				'resolutions' => $options['resolutions'] ?? ['720p', '480p', '240p'],
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
		$supportedMimes = ['video/quicktime', 'video/mp4'];

		try {
			if (!$userFolder->nodeExists($directoryPath)) {
				throw new \Exception("Directory not found: $directoryPath");
			}

			$directory = $userFolder->get($directoryPath);
			if (!($directory instanceof \OCP\Files\Folder)) {
				throw new \Exception("Path is not a directory: $directoryPath");
			}

			$this->scanFolderRecursively($directory, $directoryPath, $supportedMimes, $videoFiles);

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
	 * Recursively scan folder for video files
	 */
	private function scanFolderRecursively($folder, string $basePath, array $supportedMimes, array &$videoFiles): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\File) {
				$mimeType = $node->getMimeType();
				if (in_array($mimeType, $supportedMimes)) {
					$relativePath = $basePath === '/' ? '/' : $basePath;
					$videoFiles[] = [
						'filename' => $node->getName(),
						'directory' => $relativePath,
						'size' => $node->getSize(),
						'mimeType' => $mimeType,
						'fullPath' => $relativePath . '/' . $node->getName()
					];
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				// Skip hidden directories and cache directories
				$folderName = $node->getName();
				if (strpos($folderName, '.') !== 0) {
					$subPath = $basePath === '/' ? '/' . $folderName : $basePath . '/' . $folderName;
					$this->scanFolderRecursively($node, $subPath, $supportedMimes, $videoFiles);
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
	public function getActiveJobs(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$activeJobs = [];

			// Get cache locations from user settings (uses PersonalSettings defaults)
			$cacheLocations = $this->getUserCacheLocations($user->getUID());

			// Scan each configured cache location
			foreach ($cacheLocations as $location) {
				$resolvedPath = $this->resolveCachePath($location, $userFolder);
				
				if ($userFolder->nodeExists($resolvedPath)) {
					$cacheFolder = $userFolder->get($resolvedPath);
					if ($cacheFolder instanceof \OCP\Files\Folder) {
						$this->scanForActiveJobsOnly($cacheFolder, $activeJobs);
					}
				}
			}

			return new JSONResponse(['activeJobs' => $activeJobs]);

		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to get active jobs'], 500);
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
			
			// Get user's cache locations (uses PersonalSettings defaults)
			$cacheLocations = $this->getUserCacheLocations($user->getUID());

			foreach ($cacheLocations as $location) {
				$resolvedPath = $this->resolveCachePath($location, $userFolder);
				
				if ($userFolder->nodeExists($resolvedPath)) {
					$cacheFolder = $userFolder->get($resolvedPath);
					if ($cacheFolder instanceof \OCP\Files\Folder) {
						// Try to find the specific job directory using decoded filename
						// First try exact match (directory name matches filename exactly)
						if ($cacheFolder->nodeExists($decodedFilename)) {
							$jobFolder = $cacheFolder->get($decodedFilename);
						} else {
							// Fallback: try with extension removed (for files with extensions)
							$jobDirName = pathinfo($decodedFilename, PATHINFO_FILENAME);
							if ($cacheFolder->nodeExists($jobDirName)) {
								$jobFolder = $cacheFolder->get($jobDirName);
							} else {
								continue; // Try next cache location
							}
						}
						
						if (isset($jobFolder)) {
							if ($jobFolder instanceof \OCP\Files\Folder && $jobFolder->nodeExists('progress.json')) {
								try {
									$progressFile = $jobFolder->get('progress.json');
									$progressData = json_decode($progressFile->getContent(), true);
									
									if ($progressData && ($progressData['status'] ?? '') === 'processing') {
										// Get cache directory size
										$cacheSize = $this->getJobCacheSize($jobFolder);
										
										return new JSONResponse([
											'cachePath' => $jobFolder->getPath(),
											'filename' => $decodedFilename, // Return decoded filename
											'progress' => $progressData['progress'] ?? 0,
											'status' => $progressData['status'] ?? 'unknown',
											'frame' => $progressData['frame'] ?? 0,
											'fps' => $progressData['fps'] ?? 0,
											'speed' => $progressData['speed'] ?? '0x',
											'time' => $progressData['time'] ?? '00:00:00',
											'bitrate' => $progressData['bitrate'] ?? '0kbits/s',
											'size' => $progressData['size'] ?? '0kB',
											'cacheSize' => $cacheSize, // Add cache directory size
											'resolutions' => $progressData['resolutions'] ?? [],
											'startTime' => $progressData['startTime'] ?? time(),
											'lastUpdate' => $progressData['lastUpdate'] ?? time()
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
			}

			// Debug info: return what we searched for
			$debugInfo = [
				'error' => 'Job not found or not active',
				'debug' => [
					'originalFilename' => $filename,
					'decodedFilename' => $decodedFilename,
					'searchedLocations' => $cacheLocations,
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

		try {
			$autoGenDirs = [];
			$allAppValues = $this->config->getAppKeys('hyperviewer');

			foreach ($allAppValues as $key) {
				if (strpos($key, 'auto_gen_') === 0) {
					$settingsJson = $this->config->getAppValue('hyperviewer', $key, '');
					if (!empty($settingsJson)) {
						$settings = json_decode($settingsJson, true);
						if ($settings && isset($settings['userId']) && $settings['userId'] === $user->getUID()) {
							$autoGenDirs[] = [
								'configKey' => $key,
								'directory' => $settings['directory'] ?? '',
								'enabled' => $settings['enabled'] ?? false,
								'resolutions' => $settings['resolutions'] ?? [],
								'cachePath' => $settings['cachePath'] ?? '',
								'registeredAt' => $settings['registeredAt'] ?? 0,
								'lastScan' => $settings['lastScan'] ?? 0
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
	 * Update auto-generation directory settings
	 * 
	 * @NoAdminRequired
	 */
	public function updateAutoGeneration(string $configKey): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		try {
			$settingsJson = $this->config->getAppValue('hyperviewer', $configKey, '');
			if (empty($settingsJson)) {
				return new JSONResponse(['error' => 'Auto-generation setting not found'], 404);
			}

			$settings = json_decode($settingsJson, true);
			if (!$settings || $settings['userId'] !== $user->getUID()) {
				return new JSONResponse(['error' => 'Unauthorized or invalid setting'], 403);
			}

			// Get updated settings from request
			$input = json_decode(file_get_contents('php://input'), true);
			
			// Update allowed fields
			if (isset($input['enabled'])) {
				$settings['enabled'] = (bool)$input['enabled'];
			}
			if (isset($input['resolutions']) && is_array($input['resolutions'])) {
				$settings['resolutions'] = $input['resolutions'];
			}
			if (isset($input['cachePath'])) {
				$settings['cachePath'] = $input['cachePath'];
			}

			// Save updated settings
			$this->config->setAppValue('hyperviewer', $configKey, json_encode($settings));

			return new JSONResponse([
				'success' => true,
				'message' => 'Auto-generation setting updated'
			]);

		} catch (\Exception $e) {
			$this->logger->error('Error updating auto-generation setting', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to update auto-generation setting'], 500);
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
				'totalJobs' => 0,
				'completedJobs' => 0,
				'pendingJobs' => 0,
				'activeJobs' => 0,
				'autoGenDirectories' => 0,
				'totalCacheSize' => 0,
				'recentJobs' => []
			];

			// Get user's cache locations (uses PersonalSettings defaults)
			$cacheLocations = $this->getUserCacheLocations($user->getUID());

			foreach ($cacheLocations as $location) {
				$resolvedPath = $this->resolveCachePath($location, $userFolder);
				
				if ($userFolder->nodeExists($resolvedPath)) {
					$cacheFolder = $userFolder->get($resolvedPath);
					if ($cacheFolder instanceof \OCP\Files\Folder) {
						$this->gatherSimpleStatistics($cacheFolder, $stats);
					}
				}
			}

			// Count auto-generation directories
			$allAppValues = $this->config->getAppKeys('hyperviewer');
			foreach ($allAppValues as $key) {
				if (strpos($key, 'auto_gen_') === 0) {
					$settingsJson = $this->config->getAppValue('hyperviewer', $key, '');
					if (!empty($settingsJson)) {
						$settings = json_decode($settingsJson, true);
						if ($settings && isset($settings['userId']) && $settings['userId'] === $user->getUID() && ($settings['enabled'] ?? false)) {
							$stats['autoGenDirectories']++;
						}
					}
				}
			}

			// Get active jobs count (use existing method for consistency)
			$activeJobsResponse = $this->getActiveJobs();
			$activeJobsData = $activeJobsResponse->getData();
			if (isset($activeJobsData['jobs'])) {
				$stats['activeJobs'] = count($activeJobsData['jobs']);
			}

			return new JSONResponse(['stats' => $stats]);

		} catch (\Exception $e) {
			$this->logger->error('Error getting job statistics', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to get statistics'], 500);
		}
	}

	/**
	 * Ultra-fast statistics using direct filesystem glob queries
	 */
	private function gatherSimpleStatistics($folder, array &$stats): void {
		try {
			$folderPath = $folder->getStorage()->getLocalFile($folder->getInternalPath());
			
			if (!$folderPath || !is_dir($folderPath)) {
				return;
			}
			
			// Count total jobs: all directories in .cached_hls
			$totalDirs = glob($folderPath . '/*', GLOB_ONLYDIR);
			$stats['totalJobs'] = count($totalDirs);
			
			// Count completed jobs: directories with HLS files
			$masterFiles = glob($folderPath . '/*/master.m3u8');
			$playlistFiles = glob($folderPath . '/*/playlist.m3u8');
			$adaptiveFiles = glob($folderPath . '/*/playlist_*p.m3u8');
			
			// Get unique directory names that have any HLS files
			$completedDirs = [];
			
			foreach ($masterFiles as $file) {
				$completedDirs[dirname($file)] = true;
			}
			foreach ($playlistFiles as $file) {
				$completedDirs[dirname($file)] = true;
			}
			foreach ($adaptiveFiles as $file) {
				$completedDirs[dirname($file)] = true;
			}
			
			$stats['completedJobs'] = count($completedDirs);
			
			// Extract completed job filenames from directory names
			$completedFilenames = [];
			foreach (array_keys($completedDirs) as $dirPath) {
				$completedFilenames[] = basename($dirPath);
			}
			$stats['completedJobFilenames'] = $completedFilenames;
			
			// Calculate pending jobs: totalJobs - completedJobs
			$stats['pendingJobs'] = $stats['totalJobs'] - $stats['completedJobs'];
			
			// Skip total cache size calculation - we'll show it per job card instead
			
		} catch (\Exception $e) {
			// Fallback to slow method if glob fails
			$this->gatherSimpleStatisticsFallback($folder, $stats);
		}
	}
	
	/**
	 * Get cache size for a specific job (formatted for display)
	 */
	private function getJobCacheSize($jobFolder): string {
		try {
			$size = 0;
			
			// Try to get the actual filesystem path for more accurate size calculation
			$folderPath = $jobFolder->getStorage()->getLocalFile($jobFolder->getInternalPath());
			
			if ($folderPath && is_dir($folderPath)) {
				// Use direct filesystem access for accurate sizes
				$files = glob($folderPath . '/*');
				foreach ($files as $file) {
					if (is_file($file)) {
						$fileSize = filesize($file);
						if ($fileSize !== false) {
							$size += $fileSize;
						}
					}
				}
			} else {
				// Fallback to Nextcloud's virtual filesystem
				$items = $jobFolder->getDirectoryListing();
				foreach ($items as $item) {
					if ($item->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
						$itemSize = $item->getSize();
						if ($itemSize > 0) {
							$size += $itemSize;
						}
					}
				}
			}
			
			// Debug logging to see what we're getting
			
			return $this->formatBytes($size);
			
		} catch (\Exception $e) {
			$this->logger->error('Error calculating cache size', [
				'folder' => $jobFolder->getName() ?? 'unknown',
				'error' => $e->getMessage()
			]);
			return '0 MB';
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
	 * Get directory size quickly (legacy method)
	 */
	private function getDirSize($dir): int {
		try {
			$size = 0;
			$files = glob($dir . '/*');
			foreach ($files as $file) {
				if (is_file($file)) {
					$size += filesize($file);
				}
			}
			return $size;
		} catch (\Exception $e) {
			return 0;
		}
	}
	
	/**
	 * Fallback method if glob doesn't work
	 */
	private function gatherSimpleStatisticsFallback($folder, array &$stats): void {
		try {
			$items = $folder->getDirectoryListing();
			
			foreach ($items as $node) {
				if ($node instanceof \OCP\Files\Folder) {
					$stats['totalJobs']++;
					
					// Check if job is completed (has HLS files)
					if ($node->nodeExists('master.m3u8') || $node->nodeExists('playlist.m3u8') || 
						$this->hasPlaylistFiles($node)) {
						$stats['completedJobs']++;
					}
				}
			}
			
			$stats['pendingJobs'] = $stats['totalJobs'] - $stats['completedJobs'];
			
		} catch (\Exception $e) {
			// Skip folders we can't access
			return;
		}
	}

	/**
	 * Check if folder has playlist files (adaptive HLS)
	 */
	private function hasPlaylistFiles($folder): bool {
		try {
			$items = $folder->getDirectoryListing();
			foreach ($items as $item) {
				if ($item->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
					$name = $item->getName();
					if (preg_match('/^playlist_\d+p\.m3u8$/', $name)) {
						return true;
					}
				}
			}
		} catch (\Exception $e) {
			// Skip if we can't read directory
		}
		return false;
	}

	/**
	 * Ultra-fast active jobs scan using glob
	 */
	private function scanForActiveJobsOnly($folder, array &$activeJobs): void {
		try {
			$folderPath = $folder->getStorage()->getLocalFile($folder->getInternalPath());
			
			if (!$folderPath || !is_dir($folderPath)) {
				return;
			}
			
			// Get all directories
			$allDirs = glob($folderPath . '/*', GLOB_ONLYDIR);
			
			// Get directories with HLS files
			$masterFiles = glob($folderPath . '/*/master.m3u8');
			$playlistFiles = glob($folderPath . '/*/playlist.m3u8');
			$adaptiveFiles = glob($folderPath . '/*/playlist_*p.m3u8');
			
			// Build set of completed directories
			$completedDirs = [];
			foreach ($masterFiles as $file) {
				$completedDirs[dirname($file)] = true;
			}
			foreach ($playlistFiles as $file) {
				$completedDirs[dirname($file)] = true;
			}
			foreach ($adaptiveFiles as $file) {
				$completedDirs[dirname($file)] = true;
			}
			
			// Find active jobs: directories without HLS files
			foreach ($allDirs as $dir) {
				if (!isset($completedDirs[$dir])) {
					$dirName = basename($dir);
					$activeJobs[] = [
						'cachePath' => $folder->getPath() . '/' . $dirName,
						'filename' => $dirName,
						'status' => 'processing'
					];
				}
			}
			
		} catch (\Exception $e) {
			// Fallback to slow method if glob fails
			$this->scanForActiveJobsFallback($folder, $activeJobs);
		}
	}
	
	/**
	 * Fallback method for active jobs if glob doesn't work
	 */
	private function scanForActiveJobsFallback($folder, array &$activeJobs): void {
		try {
			$items = $folder->getDirectoryListing();
			
			foreach ($items as $node) {
				if ($node instanceof \OCP\Files\Folder) {
					// Check if job is NOT completed (no HLS files)
					$hasHlsFiles = $node->nodeExists('master.m3u8') || 
								   $node->nodeExists('playlist.m3u8') || 
								   $this->hasPlaylistFiles($node);
					
					if (!$hasHlsFiles) {
						// This is an active/pending job - just return the name
						$activeJobs[] = [
							'cachePath' => $node->getPath(),
							'filename' => $node->getName(),
							'status' => 'processing'
						];
					}
				}
			}
		} catch (\Exception $e) {
			// Skip folders we can't access
			return;
		}
	}

	/**
	 * Scan folder for progress files (legacy method for statistics)
	 */
	private function scanForProgressFiles($folder, array &$activeJobs): void {
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof \OCP\Files\Folder) {
					// Check for progress.json in this cache folder
					if ($node->nodeExists('progress.json')) {
						try {
							$progressFile = $node->get('progress.json');
							$progressData = json_decode($progressFile->getContent(), true);
							
							if ($progressData && ($progressData['status'] ?? '') === 'processing') {
								$activeJobs[] = [
									'cachePath' => $node->getPath(),
									'filename' => $progressData['filename'] ?? 'Unknown',
									'progress' => $progressData['progress'] ?? 0,
									'status' => $progressData['status'] ?? 'unknown',
									'frame' => $progressData['frame'] ?? 0,
									'fps' => $progressData['fps'] ?? 0,
									'speed' => $progressData['speed'] ?? '0x',
									'time' => $progressData['time'] ?? '00:00:00',
									'resolutions' => $progressData['resolutions'] ?? [],
									'startTime' => $progressData['startTime'] ?? time()
								];
							}
						} catch (\Exception $e) {
							// Skip invalid progress files
							continue;
						}
					}
					
					// Recursively scan subdirectories
					$this->scanForProgressFiles($node, $activeJobs);
				}
			}
		} catch (\Exception $e) {
			// Skip folders we can't access
			return;
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
			$cachePath = $this->findHlsCache($userFolder, $filename, $directory, $user->getUID());
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
		// Handle tilde (~) as reference to user home directory
		if ($path === '~' || str_starts_with($path, '~/')) {
			// Remove ~ and leading slash - we're already in user folder context
			$path = substr($path, 1);
		}
		
		// Remove leading slash
		return ltrim($path, '/');
	}
}
