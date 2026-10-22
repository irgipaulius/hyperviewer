<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Media> */
class MediaMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hv_media', Media::class);
	}

	public function findByFingerprint(string $fingerprint): ?Media {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('fingerprint', $qb->createNamedParameter($fingerprint)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function findById(int $id): Media {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Media[] */
	public function findCandidatesForGc(int $graceSeconds, int $limit = 100): array {
		$cutoff = time() - $graceSeconds;
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*')
			->from($this->getTableName(), 'm')
			->leftJoin('m', 'hv_locations', 'l', $qb->expr()->eq('m.id', 'l.media_id'))
			->where($qb->expr()->isNull('l.id'))
			->andWhere($qb->expr()->eq('m.pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('m.last_access_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function touchAccess(int $mediaId): void {
		$media = $this->findById($mediaId);
		$media->setLastAccessAt(time());
		$this->update($media);
	}
}
