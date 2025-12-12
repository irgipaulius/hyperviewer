<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class HlsService {

	private IRootFolder $rootFolder;
	private LoggerInterface $logger;
	private CachedHlsDirectoryService $cachedHlsService;

	public function __construct(
		IRootFolder $rootFolder,
		LoggerInterface $logger,
		CachedHlsDirectoryService $cachedHlsService
	) {
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
		$this->cachedHlsService = $cachedHlsService;
	}

	/**
	 * Transcode a video file to HLS
	 */
	public function transcode(string $userId, string $filename, string $directory, array $settings): void {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		
		// Handle empty directory (root) - avoid double slashes
		$videoPath = ($directory === '' || $directory === '/') 
			? $filename 
			: $directory . '/' . $filename;
		
		if (!$userFolder->nodeExists($videoPath)) {
			throw new \Exception("Video file not found: path: $videoPath dir: $directory file: $filename");
		}

		$videoFile = $userFolder->get($videoPath);
		$videoLocalPath = $videoFile->getStorage()->getLocalFile($videoFile->getInternalPath());
		
		$cachePath = $settings['cachePath'] ?? '';
		if (empty($cachePath)) {
			throw new \Exception('Cache path is required');
		}

		// Resolve tilde (~) in cache path
		$resolvedCachePath = PathResolver::resolveCachePath($cachePath);

		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		$cacheOutputPath = rtrim($resolvedCachePath, '/') . '/' . $baseFilename;

		// Create cache directory if needed
		if (!$userFolder->nodeExists($cacheOutputPath)) {
			$userFolder->newFolder($cacheOutputPath);
			
			// Refresh cache to pick up new directory (maintains proper mount order)
			$this->cachedHlsService->refreshCache($userId);
		}
		$cacheFolder = $userFolder->get($cacheOutputPath);
		$cacheLocalPath = $cacheFolder->getStorage()->getLocalFile($cacheFolder->getInternalPath());

		$this->logger->error(sprintf(
			'Transcoding: file=%s, videoPath=%s, videoLocalPath=%s, cacheOutputPath=%s, cacheLocalPath=%s',
			$filename,
			$videoPath,
			$videoLocalPath,
			$cacheOutputPath,
			$cacheLocalPath
		));

		// Generate HLS
		$resolutions = $settings['resolutions'] ?? ['720p', '480p', '240p'];
		$this->generateAdaptiveHls($videoLocalPath, $cacheLocalPath, $filename, $resolutions);
	}


	/**
	 * Generate adaptive HLS
	 */
	private function generateAdaptiveHls(string $inputPath, string $outputPath, string $filename, array $resolutions): void {
		// Define variants
		$allVariants = [
			'1080p' => ['resolution' => '1920x1080', 'bitrate' => '5000k'],
			'720p' => ['resolution' => '1280x720', 'bitrate' => '2500k'],
			'480p' => ['resolution' => '854x480', 'bitrate' => '1000k'],
			'360p' => ['resolution' => '640x360', 'bitrate' => '700k'],
			'240p' => ['resolution' => '426x240', 'bitrate' => '400k']
		];

		$variants = [];
		foreach ($resolutions as $res) {
			if (isset($allVariants[$res])) {
				$variants[$res] = $allVariants[$res];
			}
		}

		if (empty($variants)) {
			// Fallback
			$variants['720p'] = $allVariants['720p'];
		}

		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -autorotate 1 -i ' . escapeshellarg($inputPath);
		
		// Check if input has audio
		$hasAudio = $this->hasAudioStream($inputPath);
		
		$streamIndex = 0;
		$streamMaps = [];
		
		foreach ($variants as $name => $variant) {
			[$baseW, $baseH] = array_map('intval', explode('x', $variant['resolution']));
			$scaleExpr = sprintf(
				"scale='if(gt(iw,ih),%d,%d)':'if(gt(iw,ih),%d,%d)',setsar=1",
				$baseW,
				$baseH,
				$baseH,
				$baseW
			);

			$ffmpegCmd .= sprintf(
				' -map 0:v:0 -c:v:%d libx264 -preset superfast -crf 23 -maxrate %s -bufsize %s -vf:v:%d %s -metadata:s:v:%d rotate=0',
				$streamIndex,
				$variant['bitrate'],
				intval($variant['bitrate']) * 2 . 'k',
				$streamIndex,
				escapeshellarg($scaleExpr),
				$streamIndex
			);
			
			if ($hasAudio) {
				$ffmpegCmd .= sprintf(' -map 0:a:0 -c:a:%d aac -b:a:%d 128k', $streamIndex, $streamIndex);
				$streamMaps[] = "v:$streamIndex,a:$streamIndex,name:$name";
			} else {
				$streamMaps[] = "v:$streamIndex,name:$name";
			}
			
			$streamIndex++;
		}

		$ffmpegCmd .= ' -f hls -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments';
		$ffmpegCmd .= ' -master_pl_name master.m3u8';
		$ffmpegCmd .= ' -var_stream_map "' . implode(' ', $streamMaps) . '"';
		$ffmpegCmd .= ' ' . escapeshellarg($outputPath . '/playlist_%v.m3u8');

		// Progress logging
		$progressFile = $outputPath . '/progress.json';
		$this->initializeProgressFile($progressFile, $filename, $resolutions);
		$ffmpegCmd .= ' -progress ' . escapeshellarg($progressFile . '.raw');

		// Execute with progress monitoring
		$this->executeFFmpegWithProgress($ffmpegCmd, $progressFile);
		
		// Mark completion in progress file
		$this->updateProgressFileCompletion($progressFile, true);
	}

	private function executeFFmpegWithProgress(string $cmd, string $progressFile): void {
		$descriptors = [
			0 => ['pipe', 'r'], // stdin
			1 => ['pipe', 'w'], // stdout
			2 => ['pipe', 'w']  // stderr
		];

		$process = proc_open($cmd, $descriptors, $pipes);

		if (is_resource($process)) {
			fclose($pipes[0]); // Close stdin

			// Non-blocking reads
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);

			$rawProgressFile = $progressFile . '.raw';
			$stderrOutput = '';

			while (true) {
				// Read all available data
				$out = stream_get_contents($pipes[1]);
				$err = stream_get_contents($pipes[2]);
				
				if ($err) {
					$stderrOutput .= $err;
					// Keep only the last 10KB to prevent memory exhaustion on long jobs
					if (strlen($stderrOutput) > 10240) {
						$stderrOutput = substr($stderrOutput, -5120);
					}
				}

				// Update progress
				$this->parseProgressFromRawFile($rawProgressFile, $progressFile);

				// Break only when both pipes are closed (process exited)
				if (feof($pipes[1]) && feof($pipes[2])) {
					break;
				}
				
				usleep(100000); // 0.1s
			}

			fclose($pipes[1]);
			fclose($pipes[2]);
			
			// We never called proc_get_status, so proc_close should return the real exit code
			$exitCode = proc_close($process);

			if ($exitCode !== 0) {
				$errorMsg = sprintf(
					'FFmpeg failed with code %d. Command: %s. Stderr: %s',
					$exitCode,
					$cmd,
					substr($stderrOutput, -5000) // Last 5000 chars of stderr
				);
				$this->logger->error($errorMsg);
				throw new \Exception("FFmpeg failed with code $exitCode");
			}
		} else {
			$this->logger->error('Failed to start FFmpeg process. Command: ' . $cmd);
			throw new \Exception("Failed to start FFmpeg process");
		}
	}

	private function parseProgressFromRawFile(string $rawFile, string $jsonFile): void {
		if (!file_exists($rawFile) || !file_exists($jsonFile)) return;

		$content = file_get_contents($rawFile);
		if (empty($content)) return;

		// Parse the last block of progress
		$lines = explode("\n", $content);
		$data = [];
		
		foreach ($lines as $line) {
			if (strpos($line, '=') !== false) {
				list($key, $val) = explode('=', trim($line), 2);
				$key = trim($key);
				$val = trim($val);
				
				if ($key === 'progress' && $val === 'end') {
					// End of a block or stream
				} else {
					$data[$key] = $val;
				}
			}
		}

		if (empty($data)) return;

		$progressData = json_decode(file_get_contents($jsonFile), true) ?: [];
		
		// Map FFmpeg keys to our format
		if (isset($data['frame'])) $progressData['frame'] = (int)$data['frame'];
		if (isset($data['fps'])) $progressData['fps'] = (float)$data['fps'];
		if (isset($data['speed'])) $progressData['speed'] = $data['speed'];
		if (isset($data['out_time'])) $progressData['time'] = substr($data['out_time'], 0, 8);
		if (isset($data['bitrate'])) $progressData['bitrate'] = $data['bitrate'];
		if (isset($data['total_size'])) $progressData['size'] = round((int)$data['total_size'] / 1024) . 'kB';

		$progressData['lastUpdate'] = time();
		
		file_put_contents($jsonFile, json_encode($progressData));
	}

	private function initializeProgressFile(string $progressFile, string $filename, array $resolutions): void {
		$data = [
			'status' => 'processing',
			'filename' => $filename,
			'resolutions' => $resolutions,
			'progress' => 0,
			'startTime' => time()
		];
		file_put_contents($progressFile, json_encode($data));
	}

	private function updateProgressFileCompletion(string $progressFile, bool $success): void {
		if (!file_exists($progressFile)) return;
		$data = json_decode(file_get_contents($progressFile), true);
		$data['status'] = $success ? 'completed' : 'failed';
		$data['progress'] = $success ? 100 : $data['progress'];
		file_put_contents($progressFile, json_encode($data));
	}

	private function hasAudioStream(string $filePath): bool {
		$cmd = '/usr/local/bin/ffprobe -v error -select_streams a -show_entries stream=index -of csv=p=0 ' . escapeshellarg($filePath);
		$output = [];
		$returnCode = 0;
		exec($cmd, $output, $returnCode);
		
		// If return code is 0 and we have output, there is an audio stream
		return $returnCode === 0 && !empty($output);
	}
}
