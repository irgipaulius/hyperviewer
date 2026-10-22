<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\FfmpegRunner;
use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\PermissionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ITempManager;

class FrameController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MediaIndexService $mediaIndex,
		private IRootFolder $rootFolder,
		private FfmpegRunner $ffmpeg,
		private ConfigService $config,
		private ITempManager $tempManager,
		private PermissionsService $permissions,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function extractFrame(): DataResponse|StreamResponse {
		return $this->extractFrameInternal();
	}

	private function extractFrameInternal(): DataResponse|StreamResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Unauthorized'], 401);
		}
		try {
			$this->permissions->assertCanUseApp($user);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], 403);
		}
		$params = $this->request->getParams();
		$body = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($body)) {
			$params = array_merge($params, $body);
		}
		$fileid = (int)($params['fileid'] ?? 0);
		$timestamp = (float)($params['timestamp'] ?? 0);
		if ($fileid <= 0) {
			$filename = (string)($params['filename'] ?? '');
			$directory = (string)($params['directory'] ?? '/');
			if ($filename !== '') {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$rel = ltrim(rtrim($directory, '/') . '/' . $filename, '/');
				if ($userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					if ($node instanceof \OCP\Files\File) {
						$fileid = $node->getId();
					}
				}
			}
		}
		if ($fileid <= 0) {
			return new DataResponse(['error' => 'fileid or filename required'], 400);
		}
		$file = $this->mediaIndex->resolveFileForUser($user->getUID(), $fileid);
		$local = $file->getStorage()->getLocalFile($file->getInternalPath());
		if ($local === false) {
			return new DataResponse(['error' => 'Local storage required'], 400);
		}
		$out = $this->tempManager->getTemporaryFile('jpg');
		try {
			$this->ffmpeg->extractFrame(
				$local,
				$out,
				$timestamp,
				$this->config->getInt(ConfigService::KEY_EXTRACT_FRAME_TIMEOUT),
			);
			$response = new StreamResponse($out);
			$response->addHeader('Content-Type', 'image/jpeg');
			return $response;
		} catch (\Throwable) {
			return new DataResponse(['error' => 'Frame extraction failed'], 500);
		}
	}
}
