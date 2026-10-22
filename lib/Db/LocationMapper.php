<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Location> */
class LocationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hv_locations', Location::class);
	}

	public function findByFileid(int $fileid): ?Location {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return Location[] */
	public function findByMediaId(int $mediaId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('media_id', $qb->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function userCanAccessMedia(string $userId, int $mediaId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('media_id', $qb->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	public function deleteByFileid(int $fileid): void {
		$loc = $this->findByFileid($fileid);
		if ($loc !== null) {
			$this->delete($loc);
		}
	}

	public function countByMediaId(int $mediaId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('media_id', $qb->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}
}
