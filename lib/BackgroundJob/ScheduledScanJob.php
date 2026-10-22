<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Db\Job;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class ScheduledScanJob extends TimedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private ConfigService $config,
		private WatchDirMapper $watchDirMapper,
		private QueueService $queueService,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$hours = $this->config->getInt(ConfigService::KEY_SCHEDULED_SCAN);
		if ($hours <= 0) {
			return;
		}
		$cmd = trim($this->config->getString(ConfigService::KEY_DISK_SLEEP_CMD));
		if ($cmd !== '') {
			exec($cmd, $out, $code);
			if ($code !== 0) {
				return;
			}
		}
		foreach ($this->watchDirMapper->findAllEnabled() as $watch) {
			$job = new Job();
			$job->setType('scan');
			$job->setUserId($watch->getUserId());
			$job->setPayload(json_encode(['path' => $watch->getPath(), 'watchId' => $watch->getId()]));
			$job->setPriority(QueueService::PRIORITY_SCAN);
			$this->queueService->enqueue($job);
		}
	}
}
