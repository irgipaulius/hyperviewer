<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Service\FFmpegProcessManager;
use OCP\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessQueue extends Command {
	private FFmpegProcessManager $processManager;

	public function __construct(FFmpegProcessManager $processManager) {
		parent::__construct();
		$this->processManager = $processManager;
	}

	protected function configure(): void {
		$this
			->setName('hyperviewer:process-queue')
			->setDescription('Process the FFmpeg queue continuously');
	}

	private int $startTime;
	private bool $shouldStop = false;
	private const MAX_RUNTIME = 21600; // 6 Hours
	private const MAX_MEMORY = 536870912; // 512 MB

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->startTime = time();
		
		// Handle signals if extension is available
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGTERM, [$this, 'handleSignal']);
			pcntl_signal(SIGINT, [$this, 'handleSignal']);
		}


		// Create PID file
		$pidFile = sys_get_temp_dir() . '/hyperviewer_worker.pid';
		if (file_exists($pidFile)) {
			$pid = (int)file_get_contents($pidFile);
			// Check if process is running
			if ($pid > 0 && posix_kill($pid, 0)) {
				$output->writeln('<error>Worker is already running (PID: ' . $pid . ')</error>');
				return 1;
			}
			// Stale PID file
			unlink($pidFile);
		}

		file_put_contents($pidFile, (string)getmypid());
		$output->writeln('<info>Worker started (PID: ' . getmypid() . ')</info>');

		try {
			while (!$this->shouldStop) {
				// 1. Process Queue
				$this->processManager->processQueue();

				// Dispatch signals
				if (function_exists('pcntl_signal_dispatch')) {
					pcntl_signal_dispatch();
				}

				// 2. Resource Checks
				if ($this->shouldRestart()) {
					$output->writeln('<info>Restarting worker due to resource limits or time...</info>');
					break;
				}

				// 3. Check if there are pending jobs
				$stats = $this->processManager->getJobStatistics();
				$active = $stats['active'] ?? 0;
				$pending = $stats['pending'] ?? 0;

				if ($active === 0 && $pending === 0) {
					$output->writeln('Queue empty, sleeping...');
					sleep(5);
				} else {
					sleep(1);
				}
			}
		} finally {
			if (file_exists($pidFile)) {
				unlink($pidFile);
			}
		}

		return 0;
	}

	public function handleSignal(int $signal): void {
		$this->shouldStop = true;
	}

	private function shouldRestart(): bool {
		// Check Runtime
		if ((time() - $this->startTime) > self::MAX_RUNTIME) {
			return true;
		}

		// Check Memory
		if (memory_get_usage(true) > self::MAX_MEMORY) {
			return true;
		}

		return false;
	}
}
