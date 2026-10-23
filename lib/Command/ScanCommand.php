<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Service\QueueService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScanCommand extends Command {
	public function __construct(
		private QueueService $queueService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hyperviewer:scan')
			->setDescription('Enqueue a folder scan job to discover videos and queue fingerprint jobs')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'User id')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory path', '/')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getOption('user');
		if ($user === '') {
			$output->writeln('<error>--user required</error>');
			return Command::FAILURE;
		}
		$path = (string)$input->getOption('path');
		if ($input->getOption('dry-run')) {
			$output->writeln('Would enqueue scan for ' . $user . ' path ' . $path);
			return Command::SUCCESS;
		}
		$job = new Job();
		$job->setType('scan');
		$job->setUserId($user);
		$job->setPayload(json_encode(['path' => $path]));
		$job->setPriority(QueueService::PRIORITY_SCAN);
		$this->queueService->enqueue($job);
		$output->writeln('Scan job enqueued');
		return Command::SUCCESS;
	}
}
