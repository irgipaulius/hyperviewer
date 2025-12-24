<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\HyperViewer\Service\QueueManager;

class ProcessQueueJob extends TimedJob {

	private QueueManager $queueManager;

	public function __construct(
		ITimeFactory $time,
		QueueManager $queueManager
	) {
		parent::__construct($time);
		$this->queueManager = $queueManager;
		$this->setInterval(1); // every 5 mins
	}

	protected function run($argument): void {
		$this->queueManager->processQueue();
	}
}
