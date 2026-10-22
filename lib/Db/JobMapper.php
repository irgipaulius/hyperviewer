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
	public function listJobs(?string $state, int $limit, int $offset, ?string $userId = null): array {
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
			}
		}
		$qb->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
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
}
