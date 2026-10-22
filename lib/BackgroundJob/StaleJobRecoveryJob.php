<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\QueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class StaleJobRecoveryJob extends TimedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private JobMapper $jobMapper,
		private QueueService $queueService,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(120);
	}

	protected function run($argument): void {
		$cutoff = time() - 120;
		foreach ($this->jobMapper->findStaleRunning($cutoff) as $job) {
			$pid = $job->getWorkerPid();
			$alive = $pid !== null && function_exists('posix_kill') && @posix_kill($pid, 0);
			if ($alive) {
				continue;
			}
			$this->queueService->markFailed($job, 'Worker lost heartbeat');
		}
	}
}
