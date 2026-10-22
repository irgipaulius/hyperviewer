<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\IConfig;

class UserPreferencesService {
	public const KEY_AUTO_GENERATE = 'auto_generate_upload';
	public const KEY_NOTIFY_DONE = 'notify_when_done';
	public const KEY_DEFAULT_RENDITIONS = 'default_renditions';
	public const KEY_CLIP_EXPORT_DIR = 'clip_export_dir';

	public function __construct(
		private IConfig $config,
		private ConfigService $appConfig,
	) {
	}

	/** @return array<string, mixed> */
	public function getAll(string $userId): array {
		return [
			self::KEY_AUTO_GENERATE => $this->getBool($userId, self::KEY_AUTO_GENERATE, false),
			self::KEY_NOTIFY_DONE => $this->getBool($userId, self::KEY_NOTIFY_DONE, true),
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
}
