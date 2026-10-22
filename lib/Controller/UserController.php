<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Service\JobDisplayService;
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
		private LogMapper $logMapper,
		private JobDisplayService $jobDisplay,
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
		$minutes = $this->sumUserReadyMinutes($uid);
		$jobsToday = $this->jobMapper->countForUserSince($uid, $since);
		$queued = $this->jobMapper->countForUserByState($uid, 'queued');

		return new JSONResponse([
			'ready_count' => $ready + $partial,
			'cache_bytes' => $bytes,
			'video_minutes_ready' => $minutes,
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

	#[NoAdminRequired]
	public function history(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$stateParam = $this->request->getParam('state');
		$state = (is_string($stateParam) && $stateParam !== '') ? $stateParam : null;
		$limit = min(200, max(1, (int)$this->request->getParam('limit', 50)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		if ($state === null) {
			$done = $this->jobMapper->listJobs('done', $limit, $offset, $user->getUID(), null);
			$failed = $this->jobMapper->listJobs('failed', $limit, $offset, $user->getUID(), null);
			$jobs = array_merge($done, $failed);
			usort($jobs, fn ($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
			$jobs = array_slice($jobs, 0, $limit);
		} else {
			$jobs = $this->jobMapper->listJobs($state, $limit, $offset, $user->getUID(), null);
		}
		return new JSONResponse([
			'jobs' => array_map(fn ($j) => $this->jobDisplay->serialize($j), $jobs),
		]);
	}

	#[NoAdminRequired]
	public function logs(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$entries = $this->logMapper->tailForUser($user->getUID(), 150);
		return new JSONResponse(['entries' => array_map(static fn ($e) => [
			'id' => $e->getId(),
			'level' => $e->getLevel(),
			'channel' => $e->getChannel(),
			'message' => $e->getMessage(),
			'job_id' => $e->getJobId(),
			'created_at' => $e->getCreatedAt(),
		], $entries)]);
	}

	#[NoAdminRequired]
	public function cancelMyQueued(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$n = $this->jobMapper->cancelQueuedForUser($user->getUID());
		return new JSONResponse(['success' => true, 'cancelled' => $n]);
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

	private function sumUserReadyMinutes(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('m.duration', 'secs'))
			->from('hv_locations', 'l')
			->innerJoin('l', 'hv_media', 'm', $qb->expr()->eq('l.media_id', 'm.id'))
			->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in(
				'm.cache_state',
				$qb->createNamedParameter(['ready', 'partial'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
			));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		$secs = (float)($row['secs'] ?? 0);
		return (int)round($secs / 60);
	}
}
