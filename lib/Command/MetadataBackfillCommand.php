<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Service\HlsEncodeService;
use OCA\HyperViewer\Service\MediaIndexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MetadataBackfillCommand extends Command {
	public function __construct(
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private MediaIndexService $mediaIndex,
		private HlsEncodeService $hlsEncode,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hyperviewer:metadata:backfill')
			->setDescription('Probe source videos and fill missing duration/fps on hv_media (for stats and minutes-ready)')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows to process', '200')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only media linked to this user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$userFilter = $input->getOption('user');
		$userFilter = is_string($userFilter) && $userFilter !== '' ? $userFilter : null;
		$ids = $this->mediaMapper->findIdsMissingDuration($limit, $userFilter);
		$updated = 0;
		foreach ($ids as $mediaId) {
			$loc = null;
			if ($userFilter !== null) {
				$loc = $this->locationMapper->findPrimaryForUserMedia($userFilter, $mediaId);
			} else {
				$loc = $this->locationMapper->findAnyLocationForMedia($mediaId);
			}
			if ($loc === null || $loc->getFileid() === null) {
				try {
					$this->mediaMapper->markProbeSkipped($mediaId);
				} catch (\Throwable) {
				}
				continue;
			}
			$userId = $loc->getUserId();
			if ($userId === '') {
				continue;
			}
			try {
				$file = $this->mediaIndex->resolveFileForUser($userId, (int)$loc->getFileid());
				$media = $this->mediaMapper->findById($mediaId);
				$this->hlsEncode->probeAndUpdateMedia($file, $media);
				$updated++;
			} catch (\Throwable $e) {
				try {
					$this->mediaMapper->markProbeSkipped($mediaId);
				} catch (\Throwable) {
				}
				$output->writeln('<comment>Skip media ' . $mediaId . ': ' . $e->getMessage() . '</comment>');
			}
		}
		$output->writeln("Updated {$updated} media row(s)");
		return Command::SUCCESS;
	}
}
