<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

class MediaController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MediaIndexService $mediaIndex,
		private JobMapper $jobMapper,
		private QueueService $queueService,
		private ConfigService $config,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$fileids = $this->request->getParam('fileids', []);
		if (!is_array($fileids)) {
			$fileids = [$fileids];
		}
		$fileids = array_map('intval', $fileids);
		$out = [];
		$jobs = $this->jobMapper->findByFileids($fileids);
		$jobsByFile = [];
		foreach ($jobs as $job) {
			$jobsByFile[(int)$job->getFileid()] = $job;
		}
		foreach ($fileids as $fileid) {
			$media = $this->mediaIndex->getMediaForFileid($fileid, $user->getUID());
			$job = $jobsByFile[$fileid] ?? null;
			$out[$fileid] = [
				'cache_state' => $media?->getCacheState() ?? 'none',
				'renditions' => json_decode($media?->getRenditions() ?? '[]', true) ?: [],
				'fingerprint' => $media?->getFingerprint(),
				'fps' => $media?->getFps(),
				'job' => $job === null ? null : [
					'id' => $job->getId(),
					'state' => $job->getState(),
					'progress' => $job->getProgress(),
					'error' => $job->getError(),
					'type' => $job->getType(),
				],
			];
		}
		return new JSONResponse(['items' => $out]);
	}

	#[NoAdminRequired]
	public function generate(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$resolutions = $this->request->getParam('resolutions', $this->config->getDefaultRenditions());
		$fileids = $this->request->getParam('fileids', []);
		if (!is_array($fileids)) {
			$fileids = $fileids !== null && $fileids !== '' ? [$fileids] : [];
		}
		$files = $this->request->getParam('files', []);
		if (is_array($files)) {
			foreach ($files as $fileData) {
				$fid = (int)($fileData['fileid'] ?? 0);
				if ($fid > 0) {
					$fileids[] = $fid;
					continue;
				}
				$filename = (string)($fileData['filename'] ?? '');
				$directory = (string)($fileData['directory'] ?? '/');
				if ($filename === '') {
					continue;
				}
				$userFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($user->getUID());
				$rel = ltrim(rtrim($directory, '/') . '/' . $filename, '/');
				if ($userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					if ($node instanceof \OCP\Files\File) {
						$fileids[] = $node->getId();
					}
				}
			}
		}
		$fileids = array_values(array_unique(array_map('intval', $fileids)));
		$enqueued = 0;
		$jobIds = [];
		foreach ($fileids as $fileid) {
			if ($fileid <= 0) {
				continue;
			}
			$this->mediaIndex->resolveFileForUser($user->getUID(), $fileid);
			try {
				$job = $this->queueService->enqueueFingerprint($user->getUID(), $fileid, [
					'resolutions' => $resolutions,
					'autoEncode' => true,
				]);
				$jobIds[] = $job->getId();
				$enqueued++;
			} catch (\RuntimeException) {
				// already queued
			}
		}
		return new JSONResponse([
			'success' => true,
			'enqueued' => $enqueued,
			'filesCount' => $enqueued,
			'jobIds' => $jobIds,
			'jobId' => $jobIds[0] ?? null,
		]);
	}

	#[NoAdminRequired]
	public function check(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		$fileid = (int)$this->request->getParam('fileid', 0);
		if ($fileid <= 0) {
			$filename = (string)$this->request->getParam('filename', '');
			$directory = (string)$this->request->getParam('directory', '/');
			if ($filename !== '') {
				$userFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($user->getUID());
				$rel = ltrim(rtrim($directory, '/') . '/' . $filename, '/');
				if ($userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					if ($node instanceof \OCP\Files\File) {
						$fileid = $node->getId();
					}
				}
			}
		}
		$media = null;
		if ($fileid > 0) {
			try {
				$file = $this->mediaIndex->resolveFileForUser($user->getUID(), $fileid);
				$media = $this->mediaIndex->getMediaForFile($file, $user->getUID());
			} catch (\Throwable) {
				$media = $this->mediaIndex->getMediaForFileid($fileid, $user->getUID());
			}
		}
		$ready = $media !== null && in_array($media->getCacheState(), ['partial', 'ready'], true);
		$activeJob = $fileid > 0 ? $this->jobMapper->findLatestActiveForFileid($fileid) : null;
		$jobPayload = null;
		if ($activeJob !== null) {
			$jobPayload = [
				'id' => $activeJob->getId(),
				'state' => $activeJob->getState(),
				'progress' => $activeJob->getProgress(),
				'type' => $activeJob->getType(),
			];
		}
		return new JSONResponse([
			'exists' => $ready,
			'cache_state' => $media?->getCacheState() ?? 'none',
			'fingerprint' => $media?->getFingerprint(),
			'fileid' => $fileid,
			'job' => $jobPayload,
		]);
	}
}
