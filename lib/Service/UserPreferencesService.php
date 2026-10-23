<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\IConfig;

class UserPreferencesService {
	public const KEY_AUTO_GENERATE = 'auto_generate_upload';
	/** @deprecated use KEY_HLS_NOTIFY_MODE */
	public const KEY_NOTIFY_DONE = 'notify_when_done';
	public const KEY_HLS_NOTIFY_MODE = 'hls_notify_mode';
	public const KEY_DEFAULT_RENDITIONS = 'default_renditions';
	public const KEY_CLIP_EXPORT_DIR = 'clip_export_dir';
	public const KEY_NOTIFY_PENDING_READY = 'notify_pending_ready';
	public const KEY_NOTIFY_PENDING_FAILED = 'notify_pending_failed';

	public const HLS_NOTIFY_OFF = 'off';
	public const HLS_NOTIFY_SUMMARY = 'summary';
	public const HLS_NOTIFY_PER_FILE = 'per_file';

	public function __construct(
		private IConfig $config,
		private ConfigService $appConfig,
	) {
	}

	/** @return array<string, mixed> */
	public function getAll(string $userId): array {
		return [
			self::KEY_AUTO_GENERATE => $this->getBool($userId, self::KEY_AUTO_GENERATE, false),
			self::KEY_HLS_NOTIFY_MODE => $this->getHlsNotifyMode($userId),
			self::KEY_DEFAULT_RENDITIONS => $this->getString(
				$userId,
				self::KEY_DEFAULT_RENDITIONS,
				implode(',', $this->appConfig->getDefaultRenditions()),
			),
			self::KEY_CLIP_EXPORT_DIR => $this->getString($userId, self::KEY_CLIP_EXPORT_DIR, '../exports'),
		];
	}

	public function set(string $userId, string $key, string $value): void {
		$this->config->setUserValue($userId, 'hyperviewer', $key, $value);
	}

	public function getBool(string $userId, string $key, bool $default): bool {
		$raw = $this->config->getUserValue($userId, 'hyperviewer', $key, $default ? 'yes' : 'no');
		return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
	}

	public function getString(string $userId, string $key, string $default): string {
		return $this->config->getUserValue($userId, 'hyperviewer', $key, $default);
	}

	public function getHlsNotifyMode(string $userId): string {
		$mode = $this->getString($userId, self::KEY_HLS_NOTIFY_MODE, '');
		if ($mode === self::HLS_NOTIFY_OFF || $mode === self::HLS_NOTIFY_SUMMARY || $mode === self::HLS_NOTIFY_PER_FILE) {
			return $mode;
		}
		// legacy notify_when_done=yes → summary; no → off
		if ($this->getBool($userId, self::KEY_NOTIFY_DONE, true)) {
			return self::HLS_NOTIFY_SUMMARY;
		}
		return self::HLS_NOTIFY_OFF;
	}

	public function incrementPendingReady(string $userId): void {
		$n = (int)$this->getString($userId, self::KEY_NOTIFY_PENDING_READY, '0');
		$this->set($userId, self::KEY_NOTIFY_PENDING_READY, (string)($n + 1));
	}

	public function incrementPendingFailed(string $userId): void {
		$n = (int)$this->getString($userId, self::KEY_NOTIFY_PENDING_FAILED, '0');
		$this->set($userId, self::KEY_NOTIFY_PENDING_FAILED, (string)($n + 1));
	}

	public function takePendingReady(string $userId): int {
		$n = (int)$this->getString($userId, self::KEY_NOTIFY_PENDING_READY, '0');
		$this->set($userId, self::KEY_NOTIFY_PENDING_READY, '0');
		return $n;
	}

	public function takePendingFailed(string $userId): int {
		$n = (int)$this->getString($userId, self::KEY_NOTIFY_PENDING_FAILED, '0');
		$this->set($userId, self::KEY_NOTIFY_PENDING_FAILED, '0');
		return $n;
	}
}
