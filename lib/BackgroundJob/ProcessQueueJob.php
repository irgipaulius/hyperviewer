<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\HyperViewer\Service\FFmpegProcessManager;
use Psr\Log\LoggerInterface;

class ProcessQueueJob extends TimedJob {

	private FFmpegProcessManager $processManager;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		FFmpegProcessManager $processManager,
		LoggerInterface $logger
	) {
		parent::__construct($time);
		$this->processManager = $processManager;
		$this->logger = $logger;
		$this->setInterval(5); // Run every 5 seconds
	}

	protected function run($argument): void {
		$startTime = time();
		$maxExecutionTime = 55; // Run for 55 seconds to allow frequent polling

		// Loop to keep processing jobs while within the time limit
		do {
			$this->processManager->processQueue();
			
			// Sleep briefly to prevent CPU spinning if queue is empty
			// But check frequently enough to pick up new jobs
			sleep(2);
		} while (time() - $startTime < $maxExecutionTime);
	}
}
