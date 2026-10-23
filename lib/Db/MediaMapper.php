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

	/** @return array<string, mixed> */
	public function getCacheSummary(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		DbAggregate::selectSum($qb, 'cache_bytes', 'bytes');
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		$total = (int)($row['cnt'] ?? 0);
		$bytes = (int)($row['bytes'] ?? 0);

		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('cache_state')
			->addSelect($qb2->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->groupBy('cache_state');
		$res2 = $qb2->executeQuery();
		$byState = [];
		while ($r = $res2->fetch()) {
			$byState[(string)$r['cache_state']] = (int)$r['cnt'];
		}
		$res2->closeCursor();

		return [
			'total_indexed' => $total,
			'ready' => (int)($byState['ready'] ?? 0),
			'partial' => (int)($byState['partial'] ?? 0),
			'none' => (int)($byState['none'] ?? 0),
			'cache_bytes_db' => $bytes,
			'by_state' => $byState,
		];
	}

	/** @return list<int> */
	public function findIdsMissingDuration(int $limit = 200, ?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('m.id')
			->from($this->getTableName(), 'm')
			->where($qb->expr()->orX(
				$qb->expr()->isNull('m.duration'),
				$qb->expr()->lte('m.duration', $qb->createNamedParameter(0)),
			))
			->andWhere($qb->expr()->in(
				'm.cache_state',
				$qb->createNamedParameter(['ready', 'partial', 'none'], IQueryBuilder::PARAM_STR_ARRAY),
			))
			->setMaxResults($limit);
		if ($userId !== null && $userId !== '') {
			$qb->innerJoin('m', 'hv_locations', 'l', $qb->expr()->eq('l.media_id', 'm.id'))
				->andWhere($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)));
		}
		$res = $qb->executeQuery();
		$ids = [];
		while ($row = $res->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$res->closeCursor();
		return $ids;
	}

	/** @return list<string> */
	public function listReadyFingerprints(int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fingerprint')
			->from($this->getTableName())
			->where($qb->expr()->eq('cache_state', $qb->createNamedParameter('ready')))
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = (string)$row['fingerprint'];
		}
		$res->closeCursor();
		return $out;
	}
}
