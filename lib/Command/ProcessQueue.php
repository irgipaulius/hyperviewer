<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Service\FFmpegProcessManager;
use OCP\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running worker that processes the FFmpeg queue.
 *
 * Behaviour:
 * - Loops, calling FFmpegProcessManager::processQueue().
 * - Exits when:
 *   - Queue has been idle (no active/pending jobs) for MAX_IDLE_TIME seconds, OR
 *   - Runtime exceeds MAX_RUNTIME seconds, OR
 *   - Memory usage exceeds MAX_MEMORY bytes, OR
 *   - A SIGINT/SIGTERM signal is received (if pcntl is available).
 *
 * PID file is used so that exactly one worker runs at a time.
 */
class ProcessQueue extends Command {

	private FFmpegProcessManager $processManager;

	private int $startTime = 0;
	private bool $shouldStop = false;

	// Maximum continuous runtime for this worker (e.g., 6 hours)
	private const MAX_RUNTIME = 21600; // 6 * 60 * 60

	// Maximum allowed memory usage in bytes (e.g., 512 MB)
	private const MAX_MEMORY = 536870912; // 512 * 1024 * 1024

	// If the queue is completely idle (no active + no pending) for this many seconds, exit.
	private const MAX_IDLE_TIME = 60;

	// PID file name must match the one used in the background job
	private const PID_FILENAME = 'hyperviewer_worker.pid';

	public function __construct(FFmpegProcessManager $processManager) {
		parent::__construct();
		$this->processManager = $processManager;
	}

	protected function configure(): void {
		$this
			->setName('hyperviewer:process-queue')
			->setDescription('Process the FFmpeg queue continuously until idle or resource limit is reached');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->startTime = \time();

		// Setup signal handling if pcntl is available
		if (\function_exists('pcntl_signal')) {
			// On newer PHP you can also call pcntl_async_signals(true);
			@\pcntl_signal(\SIGTERM, [$this, 'handleSignal']);
			@\pcntl_signal(\SIGINT, [$this, 'handleSignal']);
		}

		$pidFile = $this->getPidFilePath();

		// Check for existing worker via PID file
		if (\file_exists($pidFile)) {
			$existingPidContent = \trim((string) @\file_get_contents($pidFile));
			$existingPid = (int) $existingPidContent;

			if ($existingPid > 0 && $this->isProcessAlive($existingPid)) {
				$output->writeln('<error>HyperViewer worker is already running (PID: ' . $existingPid . ')</error>');
				return 1;
			}

			// Stale PID file – clean it up
			@\unlink($pidFile);
		}

		// Write our own PID
		$currentPid = \getmypid() ?: 0;
		@\file_put_contents($pidFile, (string) $currentPid);

		$output->writeln('<info>HyperViewer worker started (PID: ' . $currentPid . ')</info>');

		$idleSince = 0;

		try {
			while (!$this->shouldStop) {
				// 1. Process queue (this should internally respect your worker limits)
				$this->processManager->processQueue();

				// 2. Dispatch pending signals (if pcntl is available)
				if (\function_exists('pcntl_signal_dispatch')) {
					@\pcntl_signal_dispatch();
				}

				// 3. Check resource limits / runtime
				if ($this->shouldRestart()) {
					$output->writeln('<info>HyperViewer worker exiting due to resource or time limit.</info>');
					break;
				}

				// 4. Check queue status
				$stats = $this->processManager->getJobStatistics();
				$active = (int) ($stats['active'] ?? 0);
				$pending = (int) ($stats['pending'] ?? 0);

				if ($active === 0 && $pending === 0) {
					// Queue is completely idle
					if ($idleSince === 0) {
						$idleSince = \time();
					}

					$elapsedIdle = \time() - $idleSince;

					if ($elapsedIdle >= self::MAX_IDLE_TIME) {
						$output->writeln('<info>HyperViewer queue idle for ' . $elapsedIdle . ' seconds, stopping worker.</info>');
						break;
					}

					$output->writeln('<comment>HyperViewer queue empty, idle ' . $elapsedIdle . 's / ' . self::MAX_IDLE_TIME . 's...</comment>');
					\sleep(5);
				} else {
					// There is work, reset idle timer and sleep briefly
					$idleSince = 0;
					\sleep(1);
				}
			}
		} finally {
			// Always clean up PID file on exit
			if (\file_exists($pidFile)) {
				@\unlink($pidFile);
			}

			$output->writeln('<info>HyperViewer worker stopped.</info>');
		}

		return 0;
	}

	public function handleSignal(int $signal): void {
		// Just mark that we should stop; the main loop will exit gracefully.
		$this->shouldStop = true;
	}

	private function shouldRestart(): bool {
		// Check total runtime
		if ((\time() - $this->startTime) > self::MAX_RUNTIME) {
			return true;
		}

		// Check memory usage
		if (\memory_get_usage(true) > self::MAX_MEMORY) {
			return true;
		}

		return false;
	}

	private function getPidFilePath(): string {
		return \sys_get_temp_dir() . '/' . self::PID_FILENAME;
	}

	private function isProcessAlive(int $pid): bool {
		if ($pid <= 0) {
			return false;
		}

		if (\function_exists('posix_kill')) {
			return @\posix_kill($pid, 0);
		}

		// If posix_kill is not available, we can’t reliably check.
		// Just say "not alive" so a new worker can be started.
		return false;
	}
}
