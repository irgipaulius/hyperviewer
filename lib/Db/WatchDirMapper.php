<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCA\HyperViewer\Service\UserPathService;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<WatchDir> */
class WatchDirMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private UserPathService $userPaths,
	) {
		parent::__construct($db, 'hv_watch_dirs', WatchDir::class);
	}

	/** @return WatchDir[] */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	/** @return WatchDir[] */
	public function findEnabledForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return WatchDir[] */
	public function findAllEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * @return array{watch: WatchDir, created: bool}
	 */
	public function upsert(
		string $userId,
		string $path,
		?int $storageId,
		array $resolutions,
		bool $enabled,
		int $priority,
	): array {
		$path = $this->userPaths->normalizeWatchPath($path, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)));
		try {
			/** @var WatchDir $existing */
			$existing = $this->findEntity($qb);
			$existing->setResolutions(json_encode($resolutions));
			$existing->setEnabled($enabled);
			$existing->setPriority($priority);
			if ($storageId !== null) {
				$existing->setStorageId($storageId);
			}
			return ['watch' => $this->update($existing), 'created' => false];
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			$wd = new WatchDir();
			$wd->setUserId($userId);
			$wd->setPath($path);
			$wd->setStorageId($storageId);
			$wd->setResolutions(json_encode($resolutions));
			$wd->setEnabled($enabled);
			$wd->setPriority($priority);
			$wd->setCreatedAt(time());
			return ['watch' => $this->insert($wd), 'created' => true];
		}
	}

	public function deleteById(int $id): void {
		$wd = new WatchDir();
		$wd->setId($id);
		$this->delete($wd);
	}
}
