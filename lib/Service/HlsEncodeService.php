<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\Media;
use OCA\HyperViewer\Db\MediaMapper;
use OCP\Files\File;

class HlsEncodeService {
	/** @var array<string, array<string, mixed>> */
	private const VARIANTS = [
		'1080p' => ['resolution' => '1920x1080', 'maxrate' => '12000k', 'bufsize' => '16000k', 'crf' => '18', 'preset' => 'medium'],
		'720p' => ['resolution' => '1280x720', 'maxrate' => '3600k', 'bufsize' => '6000k', 'crf' => '23', 'preset' => 'superfast'],
		'480p' => ['resolution' => '854x480', 'maxrate' => '1000k', 'bufsize' => '1600k', 'crf' => '26', 'preset' => 'superfast'],
		'360p' => ['resolution' => '640x360', 'maxrate' => '600k', 'bufsize' => '1000k', 'crf' => '28', 'preset' => 'superfast'],
		'240p' => ['resolution' => '426x240', 'maxrate' => '400k', 'bufsize' => '600k', 'crf' => '30', 'preset' => 'superfast'],
	];

	public function __construct(
		private FfmpegRunner $ffmpeg,
		private ConfigService $config,
		private CacheStoreService $cacheStore,
		private MediaMapper $mediaMapper,
		private QueueService $queueService,
	) {
	}

	public function probeAndUpdateMedia(File $file, Media $media): float {
		$local = $this->requireLocalPath($file);
		$json = $this->ffmpeg->runFfprobe([
			'-v', 'quiet',
			'-print_format', 'json',
			'-show_format', '-show_streams',
			$local,
		]);
		$data = json_decode($json, true);
		$duration = (float)($data['format']['duration'] ?? 0);
		$media->setDuration($duration);
		$hasAudio = false;
		foreach ($data['streams'] ?? [] as $stream) {
			if (($stream['codec_type'] ?? '') === 'video') {
				$media->setWidth((int)($stream['width'] ?? 0));
				$media->setHeight((int)($stream['height'] ?? 0));
				if (isset($stream['r_frame_rate']) && str_contains((string)$stream['r_frame_rate'], '/')) {
					[$n, $d] = array_map('floatval', explode('/', (string)$stream['r_frame_rate']));
					if ($d > 0) {
						$media->setFps($n / $d);
					}
				}
			}
			if (($stream['codec_type'] ?? '') === 'audio') {
				$hasAudio = true;
			}
		}
		$media->setHasAudio($hasAudio);
		$this->mediaMapper->update($media);
		return $duration;
	}

	public function encodeJob(Job $job, File $file, Media $media, callable $shouldCancel, callable $onProgress): void {
		$payload = json_decode($job->getPayload() ?? '{}', true) ?: [];
		$resolutions = $payload['resolutions'] ?? $this->config->getDefaultRenditions();
		$resolutions = array_values(array_filter($resolutions, fn ($r) => isset(self::VARIANTS[$r])));
		if ($resolutions === []) {
			throw new \RuntimeException('No valid renditions selected');
		}
		$local = $this->requireLocalPath($file);
		$duration = $media->getDuration() ?? $this->probeAndUpdateMedia($file, $media);
		$tempDir = $this->cacheStore->getTempEncodeDir($media->getFingerprint(), $job->getId());
		$isFirstOnly = $job->getType() === 'encode';
		if (!$isFirstOnly) {
			$existingDir = $this->cacheStore->getMediaDir($media->getFingerprint(), false);
			if ($existingDir !== '' && is_dir($existingDir)) {
				$this->cacheStore->copyDirectory($existingDir, $tempDir);
			}
		}
		usort($resolutions, fn ($a, $b) => $this->renditionRank($a) <=> $this->renditionRank($b));
		if ($isFirstOnly) {
			$toEncode = [$resolutions[0]];
		} else {
			$existing = json_decode($media->getRenditions() ?? '[]', true) ?: [];
			$toEncode = array_values(array_diff($resolutions, $existing));
		}
		foreach ($toEncode as $name) {
			$this->encodeSingleRendition($local, $tempDir, $name, $duration, $media->getHasAudio(), $shouldCancel, $onProgress);
		}
		$this->writeMaster($tempDir, array_unique(array_merge(
			json_decode($media->getRenditions() ?? '[]', true) ?: [],
			$toEncode,
		)));
		$renditionsDone = array_unique(array_merge(
			json_decode($media->getRenditions() ?? '[]', true) ?: [],
			$toEncode,
		));
		$media->setRenditions(json_encode(array_values($renditionsDone)));
		if ($isFirstOnly || count($renditionsDone) < count($resolutions)) {
			$media->setCacheState('partial');
		} else {
			$media->setCacheState('ready');
		}
		if ($isFirstOnly && count($resolutions) > 1) {
			$this->cacheStore->finalizeEncode($media->getFingerprint(), $tempDir);
			$media->setCacheBytes($this->cacheStore->computeCacheBytes($media->getFingerprint()));
			$this->mediaMapper->update($media);
			$this->queueService->enqueueEncode($job->getUserId(), (int)$job->getFileid(), $media->getId(), $resolutions, false);
			return;
		}
		$this->cacheStore->finalizeEncode($media->getFingerprint(), $tempDir);
		$media->setCacheBytes($this->cacheStore->computeCacheBytes($media->getFingerprint()));
		$media->setCacheState('ready');
		$this->mediaMapper->update($media);
	}

	private function encodeSingleRendition(
		string $input,
		string $outputDir,
		string $name,
		float $duration,
		bool $hasAudio,
		callable $shouldCancel,
		callable $onProgress,
	): void {
		$v = self::VARIANTS[$name];
		$threads = (string)$this->config->getInt(ConfigService::KEY_THREADS);
		$playlist = $outputDir . '/playlist_' . $name . '.m3u8';
		$args = [
			'-y', '-fflags', '+genpts', '-avoid_negative_ts', 'make_zero', '-autorotate', '1',
			'-threads', $threads, '-filter_threads', '1',
			'-i', $input,
			'-map', '0:v:0',
			'-c:v', 'libx264', '-preset', (string)$v['preset'], '-crf', (string)$v['crf'],
			'-maxrate', (string)$v['maxrate'], '-bufsize', (string)$v['bufsize'],
			'-s:v', (string)$v['resolution'],
		];
		if ($hasAudio) {
			$args = array_merge($args, ['-map', '0:a:0?', '-c:a', 'aac', '-b:a', '128k']);
		}
		$args = array_merge($args, [
			'-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod',
			'-hls_flags', 'independent_segments',
			'-progress', 'pipe:1',
			$playlist,
		]);
		$code = $this->ffmpeg->run($args, $duration, $onProgress, $shouldCancel);
		if ($code !== 0) {
			$err = $this->ffmpeg->getLastStderr();
			throw new \RuntimeException('ffmpeg failed for rendition ' . $name . ' (exit ' . $code . ')' . ($err !== '' ? ': ' . substr($err, -500) : ''));
		}
	}

	/** @param list<string> $renditions */
	private function writeMaster(string $dir, array $renditions): void {
		$lines = ['#EXTM3U', '#EXT-X-VERSION:3'];
		foreach ($renditions as $name) {
			if (!isset(self::VARIANTS[$name])) {
				continue;
			}
			$playlist = 'playlist_' . $name . '.m3u8';
			if (!is_file($dir . '/' . $playlist)) {
				continue;
			}
			$res = self::VARIANTS[$name]['resolution'];
			[$w, $h] = array_map('intval', explode('x', $res));
			$bw = (int)str_replace(['k', 'K'], '000', (string)self::VARIANTS[$name]['maxrate']);
			$lines[] = '#EXT-X-STREAM-INF:BANDWIDTH=' . $bw . ',RESOLUTION=' . $w . 'x' . $h;
			$lines[] = $playlist;
		}
		$master = $dir . '/master.m3u8';
		$tmp = $master . '.tmp';
		file_put_contents($tmp, implode("\n", $lines) . "\n");
		rename($tmp, $master);
	}

	private function renditionRank(string $name): int {
		return match ($name) {
			'240p' => 1,
			'360p' => 2,
			'480p' => 3,
			'720p' => 4,
			'1080p' => 5,
			default => 99,
		};
	}

	private function requireLocalPath(File $file): string {
		$local = $file->getStorage()->getLocalFile($file->getInternalPath());
		if ($local === false || !is_readable($local)) {
			throw new \RuntimeException('Source must be on local storage for encoding');
		}
		return $local;
	}
}
