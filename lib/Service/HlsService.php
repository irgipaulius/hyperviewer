<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class HlsService {

	private IRootFolder $rootFolder;
	private LoggerInterface $logger;

	public function __construct(
		IRootFolder $rootFolder,
		LoggerInterface $logger
	) {
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
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
			throw new \Exception("Video file not found: $videoPath");
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

		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -i ' . escapeshellarg($inputPath);
		
		$streamIndex = 0;
		$streamMaps = [];
		
		foreach ($variants as $name => $variant) {
			$ffmpegCmd .= sprintf(
				' -map 0:v:0 -c:v:%d libx264 -preset superfast -crf 23 -maxrate %s -bufsize %s -s:v:%d %s',
				$streamIndex, $variant['bitrate'], intval($variant['bitrate']) * 2 . 'k', $streamIndex, $variant['resolution']
			);
			$ffmpegCmd .= sprintf(' -map 0:a:0 -c:a:%d aac -b:a:%d 128k', $streamIndex, $streamIndex);
			$streamMaps[] = "v:$streamIndex,a:$streamIndex,name:$name";
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
		$this->logger->error('FFmpeg command: ' . $cmd);
		
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
				$status = proc_get_status($process);
				
				// Read output to prevent buffer filling
				fread($pipes[1], 4096);
				$stderr = fread($pipes[2], 4096);
				if ($stderr) {
					$stderrOutput .= $stderr;
				}

				// Update progress
				$this->parseProgressFromRawFile($rawProgressFile, $progressFile);

				if (!$status['running']) {
					break;
				}
				
				usleep(500000); // 0.5s
			}

			fclose($pipes[1]);
			fclose($pipes[2]);
			
			$returnCode = proc_close($process);

			if ($returnCode !== 0) {
				$errorMsg = sprintf(
					'FFmpeg failed with code %d. Command: %s. Stderr: %s',
					$returnCode,
					$cmd,
					substr($stderrOutput, -500) // Last 500 chars of stderr
				);
				$this->logger->error($errorMsg);
				throw new \Exception("FFmpeg failed with code $returnCode");
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
}
