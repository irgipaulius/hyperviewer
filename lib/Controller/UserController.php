<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class UserController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private JobMapper $jobMapper,
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$uid = $user->getUID();
		$since = strtotime('today midnight');
		$ready = $this->countUserMediaInState($uid, 'ready');
		$partial = $this->countUserMediaInState($uid, 'partial');
		$bytes = $this->sumUserCacheBytes($uid);
		$jobsToday = $this->jobMapper->countForUserSince($uid, $since);
		$queued = $this->jobMapper->countForUserByState($uid, 'queued');

		return new JSONResponse([
			'ready_count' => $ready + $partial,
			'cache_bytes' => $bytes,
			'jobs_today' => $jobsToday,
			'queued' => $queued,
		]);
	}

	#[NoAdminRequired]
	public function library(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$items = $this->locationMapper->listLibraryForUser($user->getUID(), 200);
		return new JSONResponse(['items' => $items]);
	}

	private function countUserMediaInState(string $userId, string $state): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('hv_locations', 'l')
			->innerJoin('l', 'hv_media', 'm', $qb->expr()->eq('l.media_id', 'm.id'))
			->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('m.cache_state', $qb->createNamedParameter($state)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	private function sumUserCacheBytes(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('m.cache_bytes', 'bytes'))
			->from('hv_locations', 'l')
			->innerJoin('l', 'hv_media', 'm', $qb->expr()->eq('l.media_id', 'm.id'))
			->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return (int)($row['bytes'] ?? 0);
	}
}
