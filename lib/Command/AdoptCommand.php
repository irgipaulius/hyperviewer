<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Service\CacheAdoptService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AdoptCommand extends Command {
	public function __construct(
		private CacheAdoptService $adoptService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hyperviewer:cache:adopt')
			->addOption('user', null, InputOption::VALUE_REQUIRED)
			->addOption('path', null, InputOption::VALUE_REQUIRED)
			->addOption('dry-run', null, InputOption::VALUE_NONE)
			->addOption('move', null, InputOption::VALUE_NONE)
			->addOption('copy', null, InputOption::VALUE_NONE)
			->addOption('limit', null, InputOption::VALUE_REQUIRED)
			->addOption('cleanup-adopted', null, InputOption::VALUE_NONE);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->adoptService->run([
			'user' => $input->getOption('user'),
			'path' => $input->getOption('path'),
			'dry_run' => (bool)$input->getOption('dry-run'),
			'move' => (bool)$input->getOption('move'),
			'copy' => !$input->getOption('move'),
			'limit' => $input->getOption('limit') !== null ? (int)$input->getOption('limit') : null,
			'cleanup_adopted' => (bool)$input->getOption('cleanup-adopted'),
		]);
		$output->writeln(json_encode($report, JSON_PRETTY_PRINT));
		return Command::SUCCESS;
	}
}
