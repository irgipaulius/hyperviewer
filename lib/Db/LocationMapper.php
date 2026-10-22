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

	public function findByStorageAndPathHash(int $storageId, string $pathHash): ?Location {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($pathHash)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
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

	/** @return list<array{fileid: int, filename: string, directory: string, cache_state: string, cache_bytes: int, files_dir: string}> */
	public function listLibraryForUser(string $userId, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.fileid', 'l.path', 'm.cache_state', 'm.cache_bytes', 'm.fingerprint', 'm.renditions', 'm.pinned', 'm.id')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'hv_media', 'm', $qb->expr()->eq('l.media_id', 'm.id'))
			->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in(
				'm.cache_state',
				$qb->createNamedParameter(['ready', 'partial'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('m.pinned', 'DESC')
			->addOrderBy('m.last_access_at', 'DESC')
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$path = (string)$row['path'];
			$dir = dirname($path);
			if ($dir === '.' || $dir === '\\') {
				$dir = '/';
			}
			$renditions = json_decode((string)($row['renditions'] ?? '[]'), true);
			$out[] = [
				'fileid' => (int)$row['fileid'],
				'media_id' => (int)$row['id'],
				'filename' => basename($path),
				'directory' => $dir,
				'files_dir' => $dir,
				'cache_state' => (string)$row['cache_state'],
				'cache_bytes' => (int)$row['cache_bytes'],
				'fingerprint' => (string)$row['fingerprint'],
				'pinned' => (bool)$row['pinned'],
				'renditions' => is_array($renditions) ? $renditions : [],
			];
		}
		$res->closeCursor();
		return $out;
	}

	public function findPrimaryForUserMedia(string $userId, int $mediaId): ?Location {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('media_id', $qb->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)))
			->orderBy('seen_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
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

	/** @return list<string> */
	public function listDistinctUserIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->neq('user_id', $qb->createNamedParameter('')));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$uid = (string)($row['user_id'] ?? '');
			if ($uid !== '') {
				$ids[] = $uid;
			}
		}
		$result->closeCursor();
		return $ids;
	}
}
