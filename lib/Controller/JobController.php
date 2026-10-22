<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\JobDisplayService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\WorkerStatusService;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class JobController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private JobMapper $jobMapper,
		private QueueService $queueService,
		private ConfigService $config,
		private WatchDirMapper $watchDirMapper,
		private IGroupManager $groupManager,
		private JobDisplayService $jobDisplay,
		private WorkerStatusService $workerStatus,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function get(int $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$job = $this->jobMapper->findById($id);
		if ($job->getUserId() !== $user->getUID() && !$this->groupManager->isAdmin($user->getUID())) {
			return new JSONResponse(['error' => 'Forbidden'], 403);
		}
		return new JSONResponse($this->jobDisplay->serialize($job));
	}

	#[NoAdminRequired]
	public function listJobs(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$state = $this->request->getParam('state');
		$type = $this->request->getParam('type');
		$filterUser = $this->request->getParam('user');
		$limit = min(200, max(1, (int)$this->request->getParam('limit', 50)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		$scope = (string)$this->request->getParam('scope', 'mine');
		$isAdmin = $this->groupManager->isAdmin($user->getUID());
		$userId = ($scope === 'all' && $isAdmin) ? null : $user->getUID();
		if ($scope === 'all' && !$isAdmin) {
			return new JSONResponse(['error' => 'Forbidden'], 403);
		}
		if ($filterUser !== null && $filterUser !== '' && $isAdmin) {
			$userId = (string)$filterUser;
		}
		$jobs = $this->jobMapper->listJobs(
			is_string($state) ? $state : null,
			$limit,
			$offset,
			$userId,
			is_string($type) ? $type : null,
		);
		return new JSONResponse([
			'jobs' => array_map(fn ($j) => $this->jobDisplay->serialize($j), $jobs),
			'offset' => $offset,
			'limit' => $limit,
		]);
	}

	#[NoAdminRequired]
	public function active(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$jobs = $this->jobMapper->findActiveByUser($user->getUID());
		$serialized = array_map(fn ($j) => $this->jobDisplay->serialize($j), $jobs);
		return new JSONResponse([
			'jobs' => $serialized,
			'activeJobs' => $serialized,
		]);
	}

	#[AdminRequired]
	public function statistics(): JSONResponse {
		$since = time() - 86400;
		return new JSONResponse([
			'queued' => $this->jobMapper->countByState('queued'),
			'running' => $this->jobMapper->countByState('running'),
			'failed' => $this->jobMapper->countByState('failed'),
			'done_24h' => $this->jobMapper->countByStateSince('done', $since),
			'cancelled' => $this->jobMapper->countByState('cancelled'),
			'queue_paused' => $this->config->getBool(ConfigService::KEY_QUEUE_PAUSED),
			'autogen' => count($this->watchDirMapper->findAllEnabled()),
			'worker' => $this->workerStatus->getSnapshot(),
		]);
	}

	#[NoAdminRequired]
	public function retry(int $id): JSONResponse {
		if (!$this->canManageJob($id)) {
			return new JSONResponse(['error' => 'Forbidden'], 403);
		}
		$this->queueService->retry($id);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse {
		if (!$this->canManageJob($id)) {
			return new JSONResponse(['error' => 'Forbidden'], 403);
		}
		$this->queueService->cancel($id);
		return new JSONResponse(['success' => true]);
	}

	#[AdminRequired]
	public function bump(int $id): JSONResponse {
		try {
			$this->queueService->bumpPriority($id);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	public function batchStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
		$ids = array_map('intval', $body['ids'] ?? []);
		$jobs = $this->jobMapper->findByIds($ids);
		$isAdmin = $this->groupManager->isAdmin($user->getUID());
		$jobs = array_values(array_filter(
			$jobs,
			fn ($j) => $isAdmin || $j->getUserId() === $user->getUID(),
		));
		return new JSONResponse([
			'jobs' => array_map(fn ($j) => $this->jobDisplay->serialize($j), $jobs),
		]);
	}

	#[NoAdminRequired]
	public function batchDelete(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
		$ids = array_map('intval', $body['ids'] ?? []);
		foreach ($ids as $id) {
			if ($this->canManageJob($id)) {
				$this->queueService->cancel($id);
			}
		}
		return new JSONResponse(['success' => true]);
	}

	#[AdminRequired]
	public function setQueuePaused(): JSONResponse {
		$paused = filter_var($this->request->getParam('paused', false), FILTER_VALIDATE_BOOLEAN);
		$this->config->setOverride(ConfigService::KEY_QUEUE_PAUSED, $paused ? 'true' : 'false');
		return new JSONResponse(['success' => true, 'paused' => $paused]);
	}

	private function canManageJob(int $id): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}
		try {
			$job = $this->jobMapper->findById($id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return false;
		}
		return $job->getUserId() === $user->getUID();
	}
}
