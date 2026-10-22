<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCP\AppFramework\Controller;
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
		return new JSONResponse($this->serializeJob($job));
	}

	#[NoAdminRequired]
	public function listJobs(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$state = $this->request->getParam('state');
		$limit = (int)$this->request->getParam('limit', 50);
		$offset = (int)$this->request->getParam('offset', 0);
		$scope = (string)$this->request->getParam('scope', 'mine');
		$userId = ($scope === 'all' && $this->groupManager->isAdmin($user->getUID())) ? null : $user->getUID();
		$jobs = $this->jobMapper->listJobs($state, $limit, $offset, $userId);
		return new JSONResponse(['jobs' => array_map([$this, 'serializeJob'], $jobs)]);
	}

	#[NoAdminRequired]
	public function active(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$jobs = $this->jobMapper->findActiveByUser($user->getUID());
		return new JSONResponse([
			'jobs' => array_map([$this, 'serializeJob'], $jobs),
			'activeJobs' => array_map([$this, 'serializeJob'], $jobs),
		]);
	}

	#[NoAdminRequired]
	public function statistics(): JSONResponse {
		return new JSONResponse([
			'active' => $this->jobMapper->countByState('running'),
			'pending' => $this->jobMapper->countByState('queued'),
			'completed' => $this->jobMapper->countByState('done'),
			'failed' => $this->jobMapper->countByState('failed'),
			'autogen' => count($this->watchDirMapper->findAllEnabled()),
		]);
	}

	#[NoAdminRequired]
	public function retry(int $id): JSONResponse {
		$this->queueService->retry($id);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse {
		$this->queueService->cancel($id);
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
		$jobs = array_values(array_filter($jobs, fn ($j) => $j->getUserId() === $user->getUID()));
		return new JSONResponse(['jobs' => array_map([$this, 'serializeJob'], $jobs)]);
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
			try {
				$job = $this->jobMapper->findById($id);
			} catch (\OCP\AppFramework\Db\DoesNotExistException) {
				continue;
			}
			if ($job->getUserId() === $user->getUID()) {
				$this->queueService->cancel($id);
			}
		}
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	public function setQueuePaused(): JSONResponse {
		$paused = filter_var($this->request->getParam('paused', false), FILTER_VALIDATE_BOOLEAN);
		$this->config->setOverride(ConfigService::KEY_QUEUE_PAUSED, $paused ? 'true' : 'false');
		return new JSONResponse(['success' => true, 'paused' => $paused]);
	}

	private function serializeJob(\OCA\HyperViewer\Db\Job $job): array {
		return [
			'id' => $job->getId(),
			'type' => $job->getType(),
			'fileid' => $job->getFileid(),
			'media_id' => $job->getMediaId(),
			'state' => $job->getState(),
			'progress' => $job->getProgress(),
			'error' => $job->getError(),
			'priority' => $job->getPriority(),
			'attempts' => $job->getAttempts(),
			'created_at' => $job->getCreatedAt(),
			'started_at' => $job->getStartedAt(),
			'finished_at' => $job->getFinishedAt(),
		];
	}
}
