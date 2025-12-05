<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Service\FFmpegProcessManager;
use OCP\Console\Bus\ICommandBus;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

class ProcessQueue extends Command {
	private FFmpegProcessManager $processManager;
	private IConfig $config;
	private LoggerInterface $logger;

	public function __construct(
		FFmpegProcessManager $processManager,
		IConfig $config,
		LoggerInterface $logger
	) {
		parent::__construct();
		$this->processManager = $processManager;
		$this->config = $config;
		$this->logger = $logger;
	}

	protected function configure(): void {
		$this
			->setName('hyperviewer:process-queue')
			->setDescription('Process the Hyper Viewer job queue');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Simple file-based locking to prevent concurrent runs
		$lockFile = \OC::$server->getTempManager()->getTemporaryFolder() . '/hyperviewer_worker.lock';
		$fp = fopen($lockFile, 'w+');

		if (!flock($fp, LOCK_EX | LOCK_NB)) {
			// Already running
			fclose($fp);
			return 0;
		}

		$this->logger->info('Starting Hyper Viewer worker via command line');

		try {
			// Run as long as we have jobs
			$hasJobs = true;
			$startTime = time();
			
			// Safety timeout (6 hours)
			$maxRuntime = 60*60*6;

			while ($hasJobs && (time() - $startTime < $maxRuntime)) {
				$this->processManager->processQueue();
				
				// Check if we still have pending/processing jobs
				$queue = $this->processManager->getQueue();
				$hasActive = false;
				foreach ($queue as $job) {
					if (in_array($job['status'], ['pending', 'processing'])) {
						$hasActive = true;
						break;
					}
				}
				
				$hasJobs = $hasActive;
				
				if ($hasJobs) {
					sleep(1);
				}
			}
		} catch (\Exception $e) {
			$this->logger->error('Worker process failed: ' . $e->getMessage());
			flock($fp, LOCK_UN);
			fclose($fp);
			return 1;
		}

		flock($fp, LOCK_UN);
		fclose($fp);
		return 0;
	}
}
