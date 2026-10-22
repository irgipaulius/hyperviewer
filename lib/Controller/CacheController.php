<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Legacy route compatibility for directory discovery and batch badge checks.
 */
class CacheController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private MediaIndexService $mediaIndex,
		private QueueService $queueService,
		private ConfigService $config,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function batchCheckCache(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$files = $this->request->getParam('files', []);
		$directory = (string)$this->request->getParam('directory', '/');
		$filenames = $this->request->getParam('filenames', []);
		if (!is_array($files) || $files === []) {
			$files = [];
			if (is_array($filenames)) {
				foreach ($filenames as $name) {
					$files[] = ['filename' => $name, 'directory' => $directory];
				}
			}
		}
		if (!is_array($files)) {
			return new JSONResponse(['error' => 'Invalid payload'], 400);
		}
		$result = [];
		$cachedVideos = [];
		foreach ($files as $fileData) {
			$fileid = (int)($fileData['fileid'] ?? 0);
			$filename = (string)($fileData['filename'] ?? '');
			$fileDirectory = (string)($fileData['directory'] ?? $directory);
			if ($fileid <= 0 && $filename !== '') {
				$directory = $fileDirectory;
				$path = rtrim($directory, '/') . '/' . $filename;
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$rel = ltrim($path, '/');
				if ($userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					if ($node instanceof \OCP\Files\File) {
						$fileid = $node->getId();
					}
				}
			}
			$media = null;
			if ($fileid > 0) {
				$media = $this->mediaIndex->getMediaForFileid($fileid, $user->getUID());
			} elseif ($filename !== '') {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$rel = ltrim(rtrim($fileDirectory, '/') . '/' . $filename, '/');
				if ($userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					if ($node instanceof \OCP\Files\File) {
						$media = $this->mediaIndex->getMediaForFile($node, $user->getUID());
						$fileid = $node->getId();
					}
				}
			}
			$state = $media?->getCacheState() ?? 'none';
			$has = in_array($state, ['partial', 'ready'], true);
			if ($has && $filename !== '') {
				$cachedVideos[] = $filename;
			}
			$result[] = [
				'filename' => $filename,
				'fileid' => $fileid,
				'hasCache' => $has,
				'cache_state' => $state,
				'fingerprint' => $media?->getFingerprint(),
			];
		}
		return new JSONResponse(['results' => $result, 'cachedVideos' => $cachedVideos]);
	}

	#[NoAdminRequired]
	public function discoverVideos(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$directory = (string)$this->request->getParam('directory', '/');
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$rel = ltrim($directory, '/');
		if (!$userFolder->nodeExists($rel)) {
			return new JSONResponse(['error' => 'Directory not found'], 404);
		}
		$folder = $userFolder->get($rel);
		if (!$folder instanceof Folder) {
			return new JSONResponse(['error' => 'Not a directory'], 400);
		}
		$videos = [];
		$this->collectVideos($folder, $videos);
		return new JSONResponse(['videos' => $videos, 'count' => count($videos)]);
	}

	private function collectVideos(Folder $folder, array &$videos): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->collectVideos($node, $videos);
				continue;
			}
			if (!$node instanceof \OCP\Files\File) {
				continue;
			}
			if (!in_array($node->getMimeType(), ['video/mp4', 'video/quicktime'], true)) {
				continue;
			}
			$videos[] = [
				'fileid' => $node->getId(),
				'filename' => $node->getName(),
				'directory' => dirname($node->getInternalPath()),
				'size' => $node->getSize(),
			];
		}
	}
}
