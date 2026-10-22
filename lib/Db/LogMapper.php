<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<LogEntry> */
class LogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hv_log', LogEntry::class);
	}

	public function append(
		string $level,
		string $channel,
		string $message,
		?array $context = null,
		?string $userId = null,
		?int $fileid = null,
		?int $jobId = null,
	): LogEntry {
		$entry = new LogEntry();
		$entry->setLevel($level);
		$entry->setChannel($channel);
		$entry->setMessage($message);
		$entry->setContext($context !== null ? json_encode($context) : null);
		$entry->setUserId($userId);
		$entry->setFileid($fileid);
		$entry->setJobId($jobId);
		$entry->setCreatedAt(time());
		return $this->insert($entry);
	}

	/** @return LogEntry[] */
	public function tail(?string $level, ?string $channel, int $limit, int $sinceId = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		if ($level !== null && $level !== '') {
			$qb->andWhere($qb->expr()->eq('level', $qb->createNamedParameter($level)));
		}
		if ($channel !== null && $channel !== '') {
			$qb->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)));
		}
		if ($sinceId > 0) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($sinceId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return LogEntry[] */
	public function tailSystemForAdmin(int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'channel',
				$qb->createNamedParameter(['worker', 'queue', 'system'], IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public static function redactMessage(string $message): string {
		$message = preg_replace('#/[\\w./-]+/\\w+\\.\\w+#', '[path]', $message) ?? $message;
		$message = preg_replace('#\\bfileid\\s*[:=]\\s*\\d+#i', 'fileid=[redacted]', $message) ?? $message;
		return $message;
	}

	public function pruneOlderThan(int $seconds): int {
		$cutoff = time() - $seconds;
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
