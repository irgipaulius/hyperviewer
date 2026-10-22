<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Service\QueueService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReindexCommand extends Command {
	public function __construct(
		private QueueService $queueService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hyperviewer:reindex')
			->setDescription('Enqueue metadata scan jobs to rebuild hv_locations from filecache')
			->addOption('user', null, InputOption::VALUE_REQUIRED)
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory path', '/');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getOption('user');
		if ($user === '') {
			$output->writeln('<error>--user required</error>');
			return Command::FAILURE;
		}
		$job = new Job();
		$job->setType('scan');
		$job->setUserId($user);
		$job->setPayload(json_encode(['path' => (string)$input->getOption('path'), 'reindex' => true]));
		$job->setPriority(QueueService::PRIORITY_SCAN);
		$this->queueService->enqueue($job);
		$output->writeln('Reindex scan enqueued');
		return Command::SUCCESS;
	}
}
