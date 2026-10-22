<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Migration;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RepairRemoveLegacyJobs implements IRepairStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	public function getName(): string {
		return 'Remove legacy Hyper Viewer background jobs from job list';
	}

	public function run(IOutput $output): void {
		$legacy = [
			'OCA\\HyperViewer\\BackgroundJob\\AutoHlsGenerationJob',
			'OCA\\HyperViewer\\BackgroundJob\\HlsCacheGenerationJob',
			'OCA\\HyperViewer\\BackgroundJob\\ProcessQueueJob',
		];
		foreach ($legacy as $class) {
			while ($this->jobList->has($class, null)) {
				$this->jobList->remove($class, null);
			}
		}
		$output->info('Legacy Hyper Viewer jobs cleared from IJobList');
	}
}
