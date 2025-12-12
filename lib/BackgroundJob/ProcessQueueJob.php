<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\HyperViewer\Service\FFmpegProcessManager;

class ProcessQueueJob extends TimedJob {

	private FFmpegProcessManager $processManager;

	public function __construct(
		ITimeFactory $time,
		FFmpegProcessManager $processManager
	) {
		parent::__construct($time);
		$this->processManager = $processManager;
		$this->setInterval(1); // every 5 mins
	}

	protected function run($argument): void {
		$this->processManager->processQueue();
	}
}
