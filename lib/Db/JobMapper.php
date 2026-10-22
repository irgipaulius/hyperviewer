<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Job> */
class JobMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hv_jobs', Job::class);
	}

	public function findById(int $id): Job {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function claimNext(): ?Job {
		$now = time();
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from($this->getTableName())
				->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
				->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
				->orderBy('priority', 'ASC')
				->addOrderBy('created_at', 'ASC')
				->setMaxResults(1)
				->forUpdate();
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			if ($row === false) {
				$this->db->commit();
				return null;
			}
			$jobId = (int)$row['id'];
			$update = $this->db->getQueryBuilder();
			$update->update($this->getTableName())
				->set('state', $update->createNamedParameter('running'))
				->set('worker_pid', $update->createNamedParameter(getmypid(), IQueryBuilder::PARAM_INT))
				->set('heartbeat_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('started_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->eq('state', $update->createNamedParameter('queued')));
			$affected = $update->executeStatement();
			$this->db->commit();
			if ($affected === 0) {
				return null;
			}
			return $this->findById($jobId);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function hasActiveForMedia(string $type, int $mediaId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('media_id', $qb->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['queued', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	public function countByState(?string $state = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		if ($state !== null) {
			$qb->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	public function countByStateSince(string $state, int $sinceTimestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->gte('finished_at', $qb->createNamedParameter($sinceTimestamp, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/** @return Job[] */
	public function findActiveByUser(string $userId, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['queued', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return Job[] */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}

	/** @return Job[] */
	public function findByFileids(array $fileids): array {
		if ($fileids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('fileid', $qb->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['queued', 'running', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	/** @return Job[] */
	public function listJobs(
		?string $state,
		int $limit,
		int $offset,
		?string $userId = null,
		?string $type = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$hasWhere = false;
		if ($userId !== null && $userId !== '') {
			$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$hasWhere = true;
		}
		if ($state !== null && $state !== '') {
			$expr = $qb->expr()->eq('state', $qb->createNamedParameter($state));
			if ($hasWhere) {
				$qb->andWhere($expr);
			} else {
				$qb->where($expr);
				$hasWhere = true;
			}
		}
		if ($type !== null && $type !== '') {
			if ($type === 'hls') {
				$expr = $qb->expr()->in(
					'type',
					$qb->createNamedParameter(['encode', 'upgrade'], IQueryBuilder::PARAM_STR_ARRAY),
				);
			} else {
				$expr = $qb->expr()->eq('type', $qb->createNamedParameter($type));
			}
			if ($hasWhere) {
				$qb->andWhere($expr);
			} else {
				$qb->where($expr);
			}
		}
		$qb->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	public function findLatestActiveForFileid(int $fileid): ?Job {
		return $this->findLatestForFileidStates($fileid, ['queued', 'running', 'failed']);
	}

	public function findLatestForFileidStates(int $fileid, array $states): ?Job {
		if ($fileid <= 0 || $states === []) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter($states, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function countForUserSince(string $userId, int $sinceTs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	public function countForUserByState(string $userId, string $state): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/** @return array<string, int> */
	public function countQueuedGroupedByType(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('type')
			->addSelect($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
			->groupBy('type');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$type = (string)$row['type'];
			if ($type === 'encode' || $type === 'upgrade') {
				$type = 'hls';
			}
			$out[$type] = ($out[$type] ?? 0) + (int)$row['cnt'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Latest chip-relevant job per fileid (queued, running, failed).
	 *
	 * @param int[] $fileids
	 * @return array<int, Job>
	 */
	public function mapLatestJobsForFileids(array $fileids): array {
		$fileids = array_values(array_unique(array_filter(array_map('intval', $fileids))));
		if ($fileids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('fileid', $qb->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in(
				'state',
				$qb->createNamedParameter(['queued', 'running', 'failed'], IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('created_at', 'DESC');
		$jobs = $this->findEntities($qb);
		$map = [];
		foreach ($jobs as $job) {
			$fid = (int)($job->getFileid() ?? 0);
			if ($fid > 0 && !isset($map[$fid])) {
				$map[$fid] = $job;
			}
		}
		return $map;
	}

	public function cancelQueuedForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$now = time();
		return $qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter('cancelled'))
			->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
			->executeStatement();
	}

	public function cancelAllFailed(): int {
		$qb = $this->db->getQueryBuilder();
		$now = time();
		return $qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter('cancelled'))
			->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('state', $qb->createNamedParameter('failed')))
			->executeStatement();
	}

	public function countQueuedAhead(Job $job): int {
		if ($job->getState() !== 'queued') {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->lt('priority', $qb->createNamedParameter($job->getPriority(), IQueryBuilder::PARAM_INT)),
					$qb->expr()->andX(
						$qb->expr()->eq('priority', $qb->createNamedParameter($job->getPriority(), IQueryBuilder::PARAM_INT)),
						$qb->expr()->lt('created_at', $qb->createNamedParameter($job->getCreatedAt(), IQueryBuilder::PARAM_INT)),
					),
				),
			);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0) + 1;
	}

	public function findFollowUpEncodeJob(int $fileid, ?int $mediaId, int $afterJobId): ?Job {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterJobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('type', $qb->createNamedParameter(['encode', 'upgrade'], IQueryBuilder::PARAM_STR_ARRAY)));
		if ($mediaId !== null && $mediaId > 0) {
			$qb->andWhere($qb->expr()->eq('media_id', $qb->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)));
		} elseif ($fileid > 0) {
			$qb->andWhere($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)));
		} else {
			return null;
		}
		$qb->orderBy('id', 'ASC')->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return Job[] */
	public function findStaleRunning(int $heartbeatCutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('running')))
			->andWhere($qb->expr()->lt('heartbeat_at', $qb->createNamedParameter($heartbeatCutoff, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function countDistinctUsersWithQueued(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(DISTINCT user_id)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	public function maxQueuedDepthPerUser(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->addSelect($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
			->groupBy('user_id');
		$result = $qb->executeQuery();
		$max = 0;
		while ($row = $result->fetch()) {
			$max = max($max, (int)$row['cnt']);
		}
		$result->closeCursor();
		return $max;
	}

	public function avgDoneEncodeDurationSince(int $sinceTimestamp): ?float {
		$qb = $this->db->getQueryBuilder();
		$qb->select('started_at', 'finished_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('done')))
			->andWhere($qb->expr()->gte('finished_at', $qb->createNamedParameter($sinceTimestamp, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'type',
				$qb->createNamedParameter(['encode', 'upgrade'], IQueryBuilder::PARAM_STR_ARRAY),
			))
			->andWhere($qb->expr()->gt('started_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$sum = 0;
		$n = 0;
		while ($row = $result->fetch()) {
			$started = (int)$row['started_at'];
			$finished = (int)$row['finished_at'];
			if ($finished > $started) {
				$sum += $finished - $started;
				$n++;
			}
		}
		$result->closeCursor();
		return $n > 0 ? round($sum / $n, 1) : null;
	}

	public function cancelAllQueued(): int {
		$qb = $this->db->getQueryBuilder();
		$now = time();
		return $qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter('cancelled'))
			->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
			->executeStatement();
	}

	/** @return list<int> counts for each of the last $days calendar days (oldest first) */
	public function countDonePerDayForUser(string $userId, int $days = 7): array {
		$days = max(1, min(30, $days));
		$out = array_fill(0, $days, 0);
		$start = strtotime('today midnight') - (($days - 1) * 86400);
		$qb = $this->db->getQueryBuilder();
		$qb->select('finished_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('done')))
			->andWhere($qb->expr()->gte('finished_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ts = (int)$row['finished_at'];
			$dayIndex = (int)floor(($ts - $start) / 86400);
			if ($dayIndex >= 0 && $dayIndex < $days) {
				$out[$dayIndex]++;
			}
		}
		$result->closeCursor();
		return $out;
	}

	public function latestFailedErrorCategory(): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('error')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('failed')))
			->orderBy('finished_at', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return self::categorizeError((string)($row['error'] ?? ''));
	}

	/** @return list<array{depth: int, users: int}> */
	public function queuedDepthHistogram(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->addSelect($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
			->groupBy('user_id');
		$result = $qb->executeQuery();
		$buckets = [];
		while ($row = $result->fetch()) {
			$depth = (int)$row['cnt'];
			$buckets[$depth] = ($buckets[$depth] ?? 0) + 1;
		}
		$result->closeCursor();
		$out = [];
		foreach ($buckets as $depth => $users) {
			$out[] = ['depth' => (int)$depth, 'users' => (int)$users];
		}
		usort($out, fn ($a, $b) => $a['depth'] <=> $b['depth']);
		return $out;
	}

	public function purgeFinishedOlderThan(int $seconds): int {
		$cutoff = time() - $seconds;
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->in(
				'state',
				$qb->createNamedParameter(['done', 'cancelled'], IQueryBuilder::PARAM_STR_ARRAY),
			))
			->andWhere($qb->expr()->lt('finished_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public static function categorizeError(string $error): string {
		$e = strtolower($error);
		if ($e === '') {
			return 'unknown';
		}
		if (str_contains($e, 'timeout') || str_contains($e, 'timed out')) {
			return 'timeout';
		}
		if (str_contains($e, 'ffmpeg') || str_contains($e, 'encode')) {
			return 'encode';
		}
		if (str_contains($e, 'disk') || str_contains($e, 'space') || str_contains($e, 'no space')) {
			return 'disk';
		}
		return 'other';
	}
}
