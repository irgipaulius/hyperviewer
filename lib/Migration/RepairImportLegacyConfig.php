<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Migration;

use OCA\HyperViewer\Db\WatchDirMapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RepairImportLegacyConfig implements IRepairStep {
	public function __construct(
		private IConfig $config,
		private WatchDirMapper $watchDirMapper,
	) {
	}

	public function getName(): string {
		return 'Import Hyper Viewer legacy auto-generation settings';
	}

	public function run(IOutput $output): void {
		$keys = $this->config->getAppKeys('hyperviewer');
		$imported = 0;

		foreach ($keys as $key) {
			if (str_starts_with($key, 'auto_gen_')) {
				$json = $this->config->getAppValue('hyperviewer', $key, '');
				if ($json === '') {
					continue;
				}
				$settings = json_decode($json, true);
				if (!is_array($settings)) {
					continue;
				}
				$userId = $settings['userId'] ?? '';
				$path = $settings['directory'] ?? '';
				if ($userId === '' || $path === '') {
					continue;
				}
				$resolutions = $settings['resolutions'] ?? ['720p', '480p', '240p'];
				$this->watchDirMapper->upsert(
					$userId,
					$path,
					isset($settings['storageId']) ? (int)$settings['storageId'] : null,
					$resolutions,
					(bool)($settings['enabled'] ?? true),
					100,
				);
				$this->config->deleteAppValue('hyperviewer', $key);
				$imported++;
			}
			if (str_starts_with($key, 'cached_hls_dirs_') || str_starts_with($key, 'cached_hls_timestamp_')) {
				$this->config->deleteAppValue('hyperviewer', $key);
			}
		}

		if ($imported > 0) {
			$output->info('Imported ' . $imported . ' auto-generation directory entries into hv_watch_dirs');
		}
	}
}
