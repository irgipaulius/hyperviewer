<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Service\FFmpegProcessManager;
use OC\Core\Command\Base;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunJobCommand extends Base {
	private FFmpegProcessManager $manager;

	public function __construct(FFmpegProcessManager $manager) {
		parent::__construct();
		$this->manager = $manager;
	}

	protected function configure(): void {
		$this->setName('hyperviewer:run-job')
			->addArgument('jobId', InputArgument::REQUIRED, 'Job ID to run');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$jobId = $input->getArgument('jobId');
		$this->manager->runJobInline($jobId);
		return 0;
	}
}
