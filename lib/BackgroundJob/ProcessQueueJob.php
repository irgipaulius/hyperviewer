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
		$this->setInterval(60); // Check every minute if worker is running
	}

	protected function run($argument): void {
		$this->processManager->processQueue();
	}
}
