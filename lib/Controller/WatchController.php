<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\QueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class WatchController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private WatchDirMapper $watchDirMapper,
		private QueueService $queueService,
		private ConfigService $config,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function list(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$items = $this->watchDirMapper->findEnabledForUser($user->getUID());
		return new JSONResponse(['directories' => array_map(static fn ($w) => [
			'id' => $w->getId(),
			'configKey' => (string)$w->getId(),
			'directory' => $w->getPath(),
			'enabled' => $w->getEnabled(),
			'resolutions' => json_decode($w->getResolutions() ?? '[]', true),
			'lastScan' => $w->getLastScanAt(),
		], $items)]);
	}

	#[NoAdminRequired]
	public function register(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$directory = (string)$this->request->getParam('directory', '');
		$resolutions = $this->request->getParam('resolutions', $this->config->getDefaultRenditions());
		$wd = $this->watchDirMapper->upsert($user->getUID(), $directory, null, $resolutions, true, 100);
		return new JSONResponse(['success' => true, 'id' => $wd->getId()]);
	}

	#[NoAdminRequired]
	public function remove(int $id): JSONResponse {
		$this->watchDirMapper->deleteById($id);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	public function scanNow(int $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$items = $this->watchDirMapper->findEnabledForUser($user->getUID());
		$path = '/';
		foreach ($items as $item) {
			if ($item->getId() === $id) {
				$path = $item->getPath();
				break;
			}
		}
		$job = new Job();
		$job->setType('scan');
		$job->setUserId($user->getUID());
		$job->setPayload(json_encode(['path' => $path, 'watchId' => $id]));
		$job->setPriority(QueueService::PRIORITY_SCAN);
		$this->queueService->enqueue($job);
		return new JSONResponse(['success' => true]);
	}
}
