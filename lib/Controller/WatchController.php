<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\PermissionsService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\UserPathService;
use OCA\HyperViewer\Service\WatchDirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

class WatchController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private WatchDirMapper $watchDirMapper,
		private QueueService $queueService,
		private ConfigService $config,
		private PermissionsService $permissions,
		private UserPathService $userPaths,
		private IRootFolder $rootFolder,
		private WatchDirectoryService $watchDirectoryService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function list(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$items = $this->watchDirMapper->findAllForUser($user->getUID());
		$uid = $user->getUID();
		return new JSONResponse(['directories' => array_map(fn ($w) => [
			'id' => $w->getId(),
			'configKey' => (string)$w->getId(),
			'directory' => $this->userPaths->normalizeWatchPath($w->getPath(), $uid),
			'enabled' => $w->getEnabled(),
			'resolutions' => json_decode($w->getResolutions() ?? '[]', true),
			'lastScan' => $w->getLastScanAt(),
		], $items)]);
	}

	#[NoAdminRequired]
	public function register(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$directory = $this->userPaths->normalizeWatchPath(
			(string)$this->request->getParam('directory', ''),
			$user->getUID(),
		);
		$resolutions = $this->request->getParam('resolutions', $this->config->getDefaultRenditions());
		$result = $this->watchDirectoryService->register($user->getUID(), $directory, $resolutions);
		return new JSONResponse(['success' => true, 'id' => $result['watch']->getId()]);
	}

	#[NoAdminRequired]
	public function folderMarkers(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$folders = [];
		foreach ($this->watchDirMapper->findEnabledForUser($user->getUID()) as $watch) {
			$path = $this->userPaths->normalizeWatchPath($watch->getPath(), $user->getUID());
			$rel = ltrim($path, '/');
			if ($rel === '' || !$userFolder->nodeExists($rel)) {
				continue;
			}
			$node = $userFolder->get($rel);
			if (!$node instanceof Folder) {
				continue;
			}
			$folders[] = [
				'fileid' => $node->getId(),
				'path' => $path,
			];
		}
		return new JSONResponse(['success' => true, 'folders' => $folders]);
	}

	#[NoAdminRequired]
	public function remove(int $id): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		foreach ($this->watchDirMapper->findEnabledForUser($user->getUID()) as $wd) {
			if ($wd->getId() === $id) {
				$this->watchDirMapper->deleteById($id);
				return new JSONResponse(['success' => true]);
			}
		}
		return new JSONResponse(['error' => 'Not found'], 404);
	}

	#[NoAdminRequired]
	public function setEnabled(int $id): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$enabled = filter_var($this->request->getParam('enabled', true), FILTER_VALIDATE_BOOLEAN);
		foreach ($this->watchDirMapper->findAllForUser($user->getUID()) as $wd) {
			if ($wd->getId() === $id) {
				$wd->setEnabled($enabled);
				$this->watchDirMapper->update($wd);
				return new JSONResponse(['success' => true, 'enabled' => $enabled]);
			}
		}
		return new JSONResponse(['error' => 'Not found'], 404);
	}

	#[NoAdminRequired]
	public function scanNow(int $id): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$items = $this->watchDirMapper->findAllForUser($user->getUID());
		$path = '/';
		foreach ($items as $item) {
			if ($item->getId() === $id) {
				$path = $item->getPath();
				break;
			}
		}
		try {
			$this->queueService->enqueueScan($user->getUID(), $path, $id);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}
		return new JSONResponse(['success' => true]);
	}

	private function guardAppUser(): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		try {
			$this->permissions->assertCanUseApp($user);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 403);
		}
		return $user;
	}
}
