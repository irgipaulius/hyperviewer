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
		// Run synchronously as a fallback for the background worker
		$this->processManager->processQueue();
	}
}
