<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Service\DbController;
use OCA\HyperViewer\Service\QueueManager;
use OCA\HyperViewer\Service\TranscodeService;
use OC\Core\Command\Base;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunJobCommand extends Base {
	private TranscodeService $transcodeService;
	private LoggerInterface $logger;

	public function __construct(TranscodeService $transcodeService, LoggerInterface $logger) {
		parent::__construct();
		$this->transcodeService = $transcodeService;
		$this->logger = $logger;
	}

	protected function configure(): void {
		$this->setName('hyperviewer:run-job')
			->addArgument('jobId', InputArgument::REQUIRED, 'Job ID to run');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$jobId = (int)$input->getArgument('jobId');
		$workerName = (gethostname() ?: 'unknown-host') . ':' . (string)(getmypid() ?: 0);
		$db = new DbController(null, $workerName);

		$job = $db->queue->getOne($jobId);
		if ($job === null) {
			return 0;
		}

		$this->logger->error(
			"RunJobCommand: start worker={$workerName} job={$jobId} file_path=" . ($job['file_path'] ?? '') .
			" cache_path=" . ($job['cache_path'] ?? '') .
			" retry_count=" . (string)($job['retry_count'] ?? 0)
		);

		$filePath = (string)($job['file_path'] ?? '');
		$cachePath = (string)($job['cache_path'] ?? '');
		$jobDataRaw = (string)($job['job_data'] ?? '{}');
		$jobData = json_decode($jobDataRaw, true);
		if (!is_array($jobData)) {
			$jobData = [];
		}
		$queueManager = new QueueManager();

		try {
			$this->transcodeService->transcode($filePath, $cachePath, $jobData);
			$queueManager->finalizeJob($jobId, $job, null);
		} catch (\Throwable $e) {
			$queueManager->finalizeJob($jobId, $job, $e);
		} finally {
			$queueManager->processQueue();
		}

		return 0;
	}
}
