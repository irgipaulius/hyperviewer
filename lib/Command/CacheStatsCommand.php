<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Command;

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
		$this->setName('hyperviewer:cache:stats');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'), $qb->func()->sum('cache_bytes', 'bytes'))
			->from('hv_media');
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
