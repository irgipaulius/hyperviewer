<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

class FfmpegInfoService {
	public function __construct(
		private ConfigService $config,
	) {
	}

	/** @return array{ffmpeg: ?string, ffprobe: ?string} */
	public function getVersionsForApi(): array {
		$ffmpeg = $this->config->getString(ConfigService::KEY_FFMPEG);
		$ffprobe = $this->config->getString(ConfigService::KEY_FFPROBE);
		return [
			'ffmpeg' => $this->probeVersion($ffmpeg),
			'ffprobe' => $this->probeVersion($ffprobe),
		];
	}

	/** @return array{ffmpeg: ?string, ffprobe: ?string, ffmpeg_path: string, ffprobe_path: string} */
	public function getVersions(): array {
		$ffmpeg = $this->config->getString(ConfigService::KEY_FFMPEG);
		$ffprobe = $this->config->getString(ConfigService::KEY_FFPROBE);
		return [
			'ffmpeg_path' => $ffmpeg,
			'ffprobe_path' => $ffprobe,
			'ffmpeg' => $this->probeVersion($ffmpeg),
			'ffprobe' => $this->probeVersion($ffprobe),
		];
	}

	private function probeVersion(string $binary): ?string {
		if ($binary === '' || !is_executable($binary)) {
			return null;
		}
		$cmd = escapeshellarg($binary) . ' -version 2>&1';
		$out = shell_exec($cmd);
		if (!is_string($out) || $out === '') {
			return null;
		}
		$line = strtok($out, "\n");
		return is_string($line) ? trim($line) : null;
	}

	public function diskFreeAtCachePath(): ?int {
		$dir = $this->config->getString(ConfigService::KEY_CACHE_DIR);
		if ($dir === '' || !is_dir($dir)) {
			return null;
		}
		$free = @disk_free_space($dir);
		return $free !== false ? (int)$free : null;
	}

	/** @return list<string> */
	public function detectHardwareEncoders(): array {
		$ffmpeg = $this->config->getString(ConfigService::KEY_FFMPEG);
		if ($ffmpeg === '' || !is_executable($ffmpeg)) {
			return [];
		}
		$cmd = escapeshellarg($ffmpeg) . ' -hide_banner -encoders 2>&1';
		$out = shell_exec($cmd);
		if (!is_string($out) || $out === '') {
			return [];
		}
		$needles = ['h264_nvenc', 'hevc_nvenc', 'h264_videotoolbox', 'h264_qsv', 'h264_vaapi'];
		$found = [];
		foreach ($needles as $n) {
			if (str_contains($out, $n)) {
				$found[] = $n;
			}
		}
		return $found;
	}
}
