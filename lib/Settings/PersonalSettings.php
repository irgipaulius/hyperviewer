<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
	private IConfig $config;
	private string $appName;

	/**
	 * SINGLE SOURCE OF TRUTH for default cache locations
	 * All code should call getUserCacheLocations() which uses these defaults
	 */
	public static function getDefaultCacheLocations(): array {
		return [
			'./.cached_hls/',
			'~/.cached_hls/',
			'/mnt/cache/.cached_hls/'
		];
	}

	public function __construct(IConfig $config, string $appName) {
		$this->config = $config;
		$this->appName = $appName;
	}

	public function getForm(): TemplateResponse {
		$userId = \OC_User::getUser();
		
		// Get current cache locations using defaults
		$cacheLocations = $this->config->getUserValue(
			$userId, 
			$this->appName, 
			'cache_locations', 
			json_encode(self::getDefaultCacheLocations())
		);

		$parameters = [
			'cache_locations' => json_decode($cacheLocations, true)
		];

		return new TemplateResponse($this->appName, 'personal-settings', $parameters, '');
	}

	public function getSection(): string {
		return 'hyperviewer';
	}

	public function getPriority(): int {
		return 50;
	}
}
