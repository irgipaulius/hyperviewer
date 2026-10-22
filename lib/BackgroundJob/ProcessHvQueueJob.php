<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCA\HyperViewer\Service\JobProcessorService;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\QueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

class ProcessHvQueueJob extends QueuedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private JobMapper $jobMapper,
		private JobProcessorService $processor,
		private QueueService $queueService,
	) {
		parent::__construct($timeFactory);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (getenv('HV_WORKER') !== '1') {
			return;
		}
		$job = $this->jobMapper->claimNext();
		if ($job === null) {
			return;
		}
		$this->processor->process($job);
		if ($this->jobMapper->countByState('queued') > 0) {
			$this->queueService->ensureWakeJob();
		}
	}
}
