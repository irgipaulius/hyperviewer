<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\JobProcessorService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\WorkerLifecycle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WorkerCommand extends Command {
	public function __construct(
		private JobMapper $jobMapper,
		private JobProcessorService $processor,
		private QueueService $queueService,
		private ConfigService $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hyperviewer:worker')
			->setDescription('Run Hyper Viewer queue worker')
			->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Worker concurrency', '1')
			->addOption('once', null, InputOption::VALUE_NONE, 'Process one job and exit');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		putenv('HV_WORKER=1');
		$_ENV['HV_WORKER'] = '1';
		WorkerLifecycle::reset();
		$once = (bool)$input->getOption('once');
		$concurrency = (int)$input->getOption('concurrency');
		if ($concurrency <= 0) {
			$concurrency = $this->config->getInt(ConfigService::KEY_CONCURRENCY);
		}
		if (function_exists('pcntl_signal')) {
			$handler = function () use ($output): void {
				WorkerLifecycle::requestTermination();
				$this->processor->requestGracefulShutdown();
				$output->writeln('<info>hyperviewer:worker received shutdown signal, stopping after current encode…</info>');
			};
			pcntl_signal(SIGTERM, $handler);
			pcntl_signal(SIGINT, $handler);
		}
		do {
			if (WorkerLifecycle::isTerminating()) {
				break;
			}
			$claimed = 0;
			for ($i = 0; $i < $concurrency; $i++) {
				if (WorkerLifecycle::isTerminating()) {
					break 2;
				}
				$job = $this->jobMapper->claimNext();
				if ($job === null) {
					break;
				}
				$claimed++;
				$this->processor->process($job);
			}
			if ($once) {
				break;
			}
			if ($claimed === 0) {
				sleep(2);
			}
			if (function_exists('pcntl_signal_dispatch')) {
				pcntl_signal_dispatch();
			}
		} while (!$once);
		if ($this->jobMapper->countByState('queued') > 0 && !WorkerLifecycle::isTerminating()) {
			$this->queueService->ensureWakeJob();
		}
		return Command::SUCCESS;
	}
}
