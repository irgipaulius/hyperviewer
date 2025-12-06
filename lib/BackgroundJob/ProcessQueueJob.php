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
		$this->setInterval(60); // Check every minute if worker is running
	}

	protected function run($argument): void {
		if (!$this->isWorkerRunning()) {
			$this->logger->info('HyperViewer worker not running, starting it...');
			$this->startWorker();
		}
	}

	private function isWorkerRunning(): bool {
		$pidFile = \OC::$server->getTempManager()->getTemporaryFolder() . '/hyperviewer_worker.pid';
		if (file_exists($pidFile)) {
			// Check if file is stale (older than 24h just in case)
			if (time() - filemtime($pidFile) > 86400) {
				return false;
			}

			$pid = (int)file_get_contents($pidFile);
			if ($pid > 0 && posix_kill($pid, 0)) {
				return true;
			}
		}
		return false;
	}

	private function startWorker(): void {
		$occPath = \OC::$SERVERROOT . '/occ';
		$cmd = PHP_BINARY . ' ' . escapeshellarg($occPath) . ' hyperviewer:process-queue';
		
		// Run in background
		$cmd .= ' > /dev/null 2>&1 &';
		
		exec($cmd);
		$this->logger->info('HyperViewer worker started via background job');
	}
}
