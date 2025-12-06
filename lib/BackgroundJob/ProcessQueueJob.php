<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\HyperViewer\Service\FFmpegProcessManager;
use Psr\Log\LoggerInterface;

/**
 * Background job that acts as a watchdog:
 * - If the worker is NOT running, start it via occ.
 * - If it IS running, do nothing.
 *
 * This job itself does NOT process the queue; it only ensures the worker exists.
 */
class ProcessQueueJob extends TimedJob {

	private FFmpegProcessManager $processManager;
	private LoggerInterface $logger;

	// PID file name must match the one used in the Command
	private const PID_FILENAME = 'hyperviewer_worker.pid';

	public function __construct(
		ITimeFactory $time,
		FFmpegProcessManager $processManager,
		LoggerInterface $logger
	) {
		parent::__construct($time);

		// Not used directly here, but keeping it injected so
		// DI configuration doesn't break and the service is initialized.
		$this->processManager = $processManager;
		$this->logger = $logger;

		// Ask Nextcloud to run this job at least every 60 seconds.
		// In practice, the actual frequency is determined by cron.php.
		$this->setInterval(60);
	}

	protected function run($argument): void {
		if ($this->isWorkerRunning()) {
			// Worker already running; nothing to do.
			return;
		}

		$this->logger->info('HyperViewer: worker not running, attempting to start it...');
		$this->startWorker();
	}

	private function getPidFilePath(): string {
		// Using system temp dir; must match the command file.
		return \sys_get_temp_dir() . '/' . self::PID_FILENAME;
	}

	private function isWorkerRunning(): bool {
		$pidFile = $this->getPidFilePath();

		if (!\file_exists($pidFile)) {
			return false;
		}

		// If PID file is older than 24h, treat as stale
		if (\time() - \filemtime($pidFile) > 86400) {
			@unlink($pidFile);
			return false;
		}

		$pidContent = \trim((string) @\file_get_contents($pidFile));
		$pid = (int) $pidContent;

		if ($pid <= 0) {
			@unlink($pidFile);
			return false;
		}

		// If posix_kill is available we can check process existence properly
		if (\function_exists('posix_kill')) {
			if (@\posix_kill($pid, 0)) {
				return true;
			}

			// Process is gone; clean up stale PID file
			@unlink($pidFile);
			return false;
		}

		// Fallback if posix_kill is not available:
		// assume that if the PID file exists and is fresh, the worker is running.
		return true;
	}

	private function startWorker(): void {
		// Path to occ
		$occPath = \OC::$SERVERROOT . '/occ';

		// Build command: php occ hyperviewer:process-queue --no-interaction
		$phpBinary = \PHP_BINARY;
		$cmd = \escapeshellarg($phpBinary) . ' ' . \escapeshellarg($occPath) . ' hyperviewer:process-queue --no-interaction';

		// Run in background, no output
		$cmd .= ' > /dev/null 2>&1 &';

		@\exec($cmd);

		$this->logger->info('HyperViewer: worker start command executed: ' . $cmd);
	}
}
