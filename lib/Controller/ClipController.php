<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\PermissionsService;
use OCA\HyperViewer\Service\QueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class ClipController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MediaIndexService $mediaIndex,
		private IRootFolder $rootFolder,
		private QueueService $queueService,
		private PermissionsService $permissions,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function exportClip(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		try {
			$this->permissions->assertCanExportClip($user);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 403);
		}
		$params = $this->request->getParams();
		$body = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($body)) {
			$params = array_merge($params, $body);
		}
		$fileid = (int)($params['fileid'] ?? 0);
		$startTime = (float)($params['startTime'] ?? 0);
		$endTime = (float)($params['endTime'] ?? 0);
		$exportPath = (string)($params['exportPath'] ?? '../exports');
		$clipFilename = (string)($params['clipFilename'] ?? 'clip.mp4');
		if ($fileid <= 0) {
			$originalPath = (string)($params['originalPath'] ?? '');
			if ($originalPath !== '') {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$rel = ltrim($originalPath, '/');
				if ($userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					if ($node instanceof \OCP\Files\File) {
						$fileid = $node->getId();
					}
				}
			}
		}
		if ($fileid <= 0) {
			return new JSONResponse(['error' => 'fileid or originalPath required'], 400);
		}
		if ($startTime >= $endTime) {
			return new JSONResponse(['error' => 'Invalid time range'], 400);
		}
		try {
			$this->mediaIndex->resolveFileForUser($user->getUID(), $fileid);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 404);
		}
		$job = $this->queueService->enqueueClip($user->getUID(), $fileid, [
			'start' => $startTime,
			'duration' => $endTime - $startTime,
			'exportDir' => $exportPath,
			'clipFilename' => $clipFilename,
		]);
		return new JSONResponse([
			'success' => true,
			'jobId' => $job->getId(),
			'message' => 'Clip export queued',
		]);
	}
}
