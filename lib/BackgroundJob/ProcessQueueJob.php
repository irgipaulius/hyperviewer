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
		FFmpegProcessManager $processManager
	) {
		parent::__construct($time);
		$this->processManager = $processManager;
		$this->setInterval(60); // Check every minute if worker is running
	}

	protected function run($argument): void {
		$this->logger->error('running process queue job');
		$startTime = time();
		// run for 5 minutes.
		while(time() - $startTime < 5 * 60) {
			$this->processManager->processQueue();
			sleep(1);
		}
	}
}
