<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use Psr\Log\LoggerInterface;

class TranscodeService {
	public const BIN_NICE = '/usr/bin/nice';
	public const BIN_FFMPEG = '/usr/local/bin/ffmpeg';
	public const BIN_FFPROBE = '/usr/local/bin/ffprobe';
	private const ALLOWED_RESOLUTIONS = ['1080p', '720p', '480p', '360p', '240p'];

	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	public function transcode(string $filePath, string $cachePath, array $settings): void {
		if ($filePath === '' || $cachePath === '') {
			throw new \InvalidArgumentException('filePath and cachePath are required');
		}

		if (!is_file($filePath)) {
			throw new \RuntimeException('Input file does not exist: ' . $filePath);
		}

		$settings = $this->sanitizeSettings($settings);
		$overwriteExisting = (bool)$settings['overwriteExisting'];
		if (!$overwriteExisting && $this->cacheLooksComplete($cachePath)) {
			return;
		}

		$this->ensureDir($cachePath);

		$resolutions = $settings['resolutions'];
		$this->generateAdaptiveHls($filePath, $cachePath, $resolutions);
	}

	private function sanitizeSettings(array $settings): array {
		$overwriteExisting = (bool)($settings['overwriteExisting'] ?? false);
		$defaultResolutions = ['720p'];
		$resolutions = $settings['resolutions'] ?? $defaultResolutions;
		$resolutions = array_values(array_filter((array)$resolutions, fn ($r) => is_string($r) && in_array($r, self::ALLOWED_RESOLUTIONS, true))) ?: $defaultResolutions;

		return [
			'overwriteExisting' => $overwriteExisting,
			'resolutions' => $resolutions,
		];
	}

	private function cacheLooksComplete(string $cachePath): bool {
		if (!is_dir($cachePath)) {
			return false;
		}

		if (is_file(rtrim($cachePath, '/') . '/master.m3u8')) {
			return true;
		}

		$anyPlaylist = glob(rtrim($cachePath, '/') . '/playlist_*.m3u8');
		return !empty($anyPlaylist);
	}

	private function ensureDir(string $path): void {
		if (is_dir($path)) {
			return;
		}

		if (!@mkdir($path, 0775, true) && !is_dir($path)) {
			throw new \RuntimeException('Failed to create directory: ' . $path);
		}
	}

	private function generateAdaptiveHls(string $inputPath, string $outputPath, array $resolutions): void {
		$allVariants = [
			'1080p' => ['resolution' => '1920x1080', 'bitrate' => '5000k'],
			'720p' => ['resolution' => '1280x720', 'bitrate' => '2500k'],
			'480p' => ['resolution' => '854x480', 'bitrate' => '1000k'],
			'360p' => ['resolution' => '640x360', 'bitrate' => '700k'],
			'240p' => ['resolution' => '426x240', 'bitrate' => '400k'],
		];

		$variants = [];
		foreach ($resolutions as $res) {
			if (isset($allVariants[$res])) {
				$variants[$res] = $allVariants[$res];
			}
		}

		if (empty($variants)) {
			$variants['720p'] = $allVariants['720p'];
		}

		$hasAudio = $this->hasAudioStream($inputPath);

		$cmd = self::BIN_NICE . ' -n 11 ' . self::BIN_FFMPEG . ' -y -autorotate 1 -i ' . escapeshellarg($inputPath);

		$streamIndex = 0;
		$streamMaps = [];

		foreach ($variants as $name => $variant) {
			$cmd .= sprintf(
				' -map 0:v:0 -c:v:%d libx264 -preset superfast -crf 23 -maxrate %s -bufsize %s -s:v:%d %s',
				$streamIndex,
				$variant['bitrate'],
				intval($variant['bitrate']) * 2 . 'k',
				$streamIndex,
				$variant['resolution']
			);

			if ($hasAudio) {
				$cmd .= sprintf(' -map 0:a:0 -c:a:%d aac -b:a:%d 128k', $streamIndex, $streamIndex);
				$streamMaps[] = "v:$streamIndex,a:$streamIndex,name:$name";
			} else {
				$streamMaps[] = "v:$streamIndex,name:$name";
			}

			$streamIndex++;
		}

		$outputPath = rtrim($outputPath, '/');
		$cmd .= ' -f hls -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments';
		$cmd .= ' -master_pl_name master.m3u8';
		$cmd .= ' -var_stream_map ' . escapeshellarg(implode(' ', $streamMaps));
		$cmd .= ' ' . escapeshellarg($outputPath . '/playlist_%v.m3u8');

		$this->executeFFmpeg($cmd);
	}

	private function executeFFmpeg(string $cmd): void {
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$this->logger->error('FFmpeg starting: ' . $cmd);
		$process = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($process)) {
			throw new \RuntimeException('Failed to start ffmpeg process');
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stderrOutput = '';
		while (true) {
			$out = stream_get_contents($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			if ($err) {
				$stderrOutput .= $err;
				if (strlen($stderrOutput) > 10240) {
					$stderrOutput = substr($stderrOutput, -5120);
				}
			}

			if (feof($pipes[1]) && feof($pipes[2])) {
				break;
			}

			usleep(100000);
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		if ($exitCode !== 0) {
			$this->logger->error(sprintf(
				'FFmpeg failed with code %d. Stderr=%s',
				$exitCode,
				substr($stderrOutput, -5000)
			));
			throw new \RuntimeException('FFmpeg failed with code ' . (string)$exitCode);
		}

		$this->logger->error('Transcode completed');
	}

	private function hasAudioStream(string $filePath): bool {
		$cmd = self::BIN_FFPROBE . ' -v error -select_streams a -show_entries stream=index -of csv=p=0 ' . escapeshellarg($filePath);
		$output = [];
		$returnCode = 0;
		exec($cmd, $output, $returnCode);
		return $returnCode === 0 && !empty($output);
	}
}
