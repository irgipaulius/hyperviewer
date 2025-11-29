<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\HyperViewer\Service\FFmpegProcessManager;

class ProcessQueueJob extends TimedJob {

	private FFmpegProcessManager $processManager;

	public function __construct(
		ITimeFactory $timeFactory,
		FFmpegProcessManager $processManager
	) {
		parent::__construct($timeFactory);
		$this->processManager = $processManager;
		
		// Run every minute
		$this->setInterval(60);
	}

	protected function run($argument): void {
		$this->processManager->processQueue();
	}
}
