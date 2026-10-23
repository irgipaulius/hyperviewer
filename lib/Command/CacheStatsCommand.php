<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

use OCA\HyperViewer\Db\DbAggregate;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CacheStatsCommand extends Command {
	public function __construct(
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hyperviewer:cache:stats')
			->setDescription('Print aggregate HLS cache row counts and total cache_bytes from hv_media');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('hv_media');
		DbAggregate::selectSum($qb, 'cache_bytes', 'bytes');
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		$output->writeln(json_encode([
			'media_count' => (int)($row['cnt'] ?? 0),
			'cache_bytes' => (int)($row['bytes'] ?? 0),
		], JSON_PRETTY_PRINT));
		return Command::SUCCESS;
	}
}
