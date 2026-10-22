<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\SettingsOverrideMapper;
use OCP\IAppConfig;

class ConfigService {
	public const KEY_FFMPEG = 'ffmpeg_path';
	public const KEY_FFPROBE = 'ffprobe_path';
	public const KEY_CACHE_DIR = 'hls_cache_directory';
	public const KEY_FINGERPRINT_MB = 'fingerprint_sample_mb';
	public const KEY_CONCURRENCY = 'worker_concurrency';
	public const KEY_THREADS = 'ffmpeg_threads';
	public const KEY_CPU_PRIORITY = 'cpu_priority_mode';
	public const KEY_ENCODE_TIMEOUT_MULT = 'encode_timeout_duration_mult';
	public const KEY_STALL_SECONDS = 'encode_stall_seconds';
	public const KEY_DEFAULT_RENDITIONS = 'default_renditions';
	public const KEY_SCHEDULED_SCAN = 'scheduled_scan_interval_hours';
	public const KEY_DISK_SLEEP_CMD = 'disk_sleep_check_command';
	public const KEY_GC_GRACE_DAYS = 'gc_grace_days';
	public const KEY_LRU_GB = 'cache_lru_limit_gb';
	public const KEY_AUTO_ENCODE_ALL = 'auto_encode_all_videos';
	public const KEY_LOG_RETENTION_DAYS = 'log_retention_days';
	public const KEY_QUEUE_PAUSED = 'queue_paused';
	public const KEY_EXTRACT_FRAME_TIMEOUT = 'extract_frame_timeout_seconds';
	public const KEY_ALLOWED_GROUPS = 'allowed_groups';
	public const KEY_GROUP_GENERATE_HLS = 'group_generate_hls';
	public const KEY_GROUP_EXPORT_CLIP = 'group_export_clip';
	public const KEY_GROUP_PUBLIC_LINKS = 'group_public_links';
	public const KEY_MAX_JOBS_PER_USER = 'max_jobs_per_user';
	public const KEY_QUIET_HOURS_START = 'quiet_hours_start';
	public const KEY_QUIET_HOURS_END = 'quiet_hours_end';
	public const KEY_USER_CACHE_QUOTA_GB = 'default_user_cache_quota_gb';
	public const KEY_USER_ALLOWED_RENDITIONS = 'user_allowed_renditions';
	public const KEY_HLS_SEGMENT_SECONDS = 'hls_segment_seconds';

	/** @var array<string, mixed> */
	private array $defaults = [
		self::KEY_FFMPEG => '',
		self::KEY_FFPROBE => '',
		self::KEY_CACHE_DIR => '',
		self::KEY_FINGERPRINT_MB => 4,
		self::KEY_CONCURRENCY => 1,
		self::KEY_THREADS => 2,
		self::KEY_CPU_PRIORITY => 'auto',
		self::KEY_ENCODE_TIMEOUT_MULT => 3,
		self::KEY_STALL_SECONDS => 300,
		self::KEY_DEFAULT_RENDITIONS => '720p,480p,360p,240p',
		self::KEY_SCHEDULED_SCAN => 0,
		self::KEY_DISK_SLEEP_CMD => '',
		self::KEY_GC_GRACE_DAYS => 7,
		self::KEY_LRU_GB => 0,
		self::KEY_AUTO_ENCODE_ALL => false,
		self::KEY_LOG_RETENTION_DAYS => 14,
		self::KEY_QUEUE_PAUSED => false,
		self::KEY_EXTRACT_FRAME_TIMEOUT => 30,
		self::KEY_ALLOWED_GROUPS => '',
		self::KEY_GROUP_GENERATE_HLS => '',
		self::KEY_GROUP_EXPORT_CLIP => '',
		self::KEY_GROUP_PUBLIC_LINKS => '',
		self::KEY_MAX_JOBS_PER_USER => 0,
		self::KEY_QUIET_HOURS_START => -1,
		self::KEY_QUIET_HOURS_END => -1,
		self::KEY_USER_CACHE_QUOTA_GB => 0,
		self::KEY_USER_ALLOWED_RENDITIONS => '',
		self::KEY_HLS_SEGMENT_SECONDS => 4,
	];

	public function __construct(
		private IAppConfig $appConfig,
		private SettingsOverrideMapper $overrideMapper,
		private BinaryFinder $binaryFinder,
	) {
	}

	public function getString(string $key): string {
		$override = $this->overrideMapper->get($key);
		if ($override !== null) {
			return $override;
		}
		$default = (string)($this->defaults[$key] ?? '');
		if ($key === self::KEY_FFMPEG) {
			return $this->appConfig->getValueString('hyperviewer', $key, $default) ?: $this->binaryFinder->findFfmpeg();
		}
		if ($key === self::KEY_FFPROBE) {
			return $this->appConfig->getValueString('hyperviewer', $key, $default) ?: $this->binaryFinder->findFfprobe();
		}
		return $this->appConfig->getValueString('hyperviewer', $key, $default);
	}

	public function getInt(string $key): int {
		$override = $this->overrideMapper->get($key);
		if ($override !== null) {
			return (int)$override;
		}
		$default = (int)($this->defaults[$key] ?? 0);
		return $this->appConfig->getValueInt('hyperviewer', $key, $default);
	}

	public function getBool(string $key): bool {
		$override = $this->overrideMapper->get($key);
		if ($override !== null) {
			return filter_var($override, FILTER_VALIDATE_BOOLEAN);
		}
		$default = (bool)($this->defaults[$key] ?? false);
		return $this->appConfig->getValueBool('hyperviewer', $key, $default);
	}

	/** @return array<string, array{value: mixed, source: string}> */
	public function getAllWithSources(): array {
		$out = [];
		foreach (array_keys($this->defaults) as $key) {
			$override = $this->overrideMapper->get($key);
			if ($override !== null) {
				$out[$key] = ['value' => $this->cast($key, $override), 'source' => 'override'];
				continue;
			}
			$detected = $this->detectedValue($key);
			if ($detected !== null) {
				$out[$key] = ['value' => $detected, 'source' => 'detected'];
				continue;
			}
			$out[$key] = ['value' => $this->defaults[$key], 'source' => 'default'];
		}
		return $out;
	}

	public function setOverride(string $key, string $value): void {
		$this->overrideMapper->set($key, $value);
	}

	public function resetOverride(string $key): void {
		$this->overrideMapper->delete($key);
	}

	/** @return list<string> */
	public function getDefaultRenditions(): array {
		$raw = $this->getString(self::KEY_DEFAULT_RENDITIONS);
		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}

	/** @return list<string> */
	public function getUserAllowedRenditions(): array {
		$raw = trim($this->getString(self::KEY_USER_ALLOWED_RENDITIONS));
		if ($raw === '') {
			return $this->getDefaultRenditions();
		}
		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}

	/** @param list<string> $requested */
	public function filterRenditions(array $requested): array {
		$allowed = $this->getUserAllowedRenditions();
		$out = array_values(array_intersect($requested, $allowed));
		return $out !== [] ? $out : $allowed;
	}

	public function defaultUserCacheQuotaBytes(): ?int {
		$gb = $this->getInt(self::KEY_USER_CACHE_QUOTA_GB);
		return $gb > 0 ? $gb * 1024 * 1024 * 1024 : null;
	}

	private function cast(string $key, string $value): mixed {
		if (isset($this->defaults[$key]) && is_bool($this->defaults[$key])) {
			return filter_var($value, FILTER_VALIDATE_BOOLEAN);
		}
		if (isset($this->defaults[$key]) && is_int($this->defaults[$key])) {
			return (int)$value;
		}
		return $value;
	}

	private function detectedValue(string $key): mixed {
		if ($key === self::KEY_FFMPEG) {
			$path = $this->binaryFinder->findFfmpeg();
			return $path !== '' ? $path : null;
		}
		if ($key === self::KEY_FFPROBE) {
			$path = $this->binaryFinder->findFfprobe();
			return $path !== '' ? $path : null;
		}
		return null;
	}
}
