<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Service\JobDisplayService;
use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\PermissionsService;
use OCA\HyperViewer\Service\PublicLinkService;
use OCA\HyperViewer\Service\UserMediaService;
use OCA\HyperViewer\Service\UserPreferencesService;
use OCA\HyperViewer\Service\WorkerStatusService;
use OCA\HyperViewer\Db\PublicLinkMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
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
		private UserMediaService $userMedia,
		private UserPreferencesService $preferences,
		private MediaIndexService $mediaIndex,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
		private PublicLinkService $publicLinks,
		private PublicLinkMapper $publicLinkMapper,
		private PermissionsService $permissions,
		private ConfigService $config,
		private WorkerStatusService $workerStatus,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function capabilities(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}
		return new JSONResponse([
			'can_use_app' => $this->permissions->canUseApp($user),
			'can_generate_hls' => $this->permissions->canGenerateHls($user),
			'can_export_clip' => $this->permissions->canExportClip($user),
			'can_create_public_link' => $this->permissions->canCreatePublicLink($user),
		]);
	}

	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$uid = $user->getUID();
		$since = strtotime('today midnight');
		$ready = $this->locationMapper->countUserMediaInState($uid, 'ready');
		$partial = $this->locationMapper->countUserMediaInState($uid, 'partial');
		$bytes = $this->locationMapper->sumCacheBytesForUser($uid);
		$minutes = (int)round($this->locationMapper->sumReadyDurationSecondsForUser($uid) / 60);
		$worker = $this->workerStatus->getPersonalHint();
		$jobsToday = $this->jobMapper->countForUserSince($uid, $since);
		$queued = $this->jobMapper->countForUserByState($uid, 'queued');
		$sparkline = $this->jobMapper->countDonePerDayForUser($uid, 7);
		$storageFree = $this->readUserStorageFree($uid);
		$quotaBytes = $this->config->defaultUserCacheQuotaBytes();

		return new JSONResponse([
			'ready_count' => $ready + $partial,
			'cache_bytes' => $bytes,
			'video_minutes_ready' => $minutes,
			'jobs_today' => $jobsToday,
			'queued' => $queued,
			'sparkline_done_7d' => $sparkline,
			'storage_free_bytes' => $storageFree,
			'cache_quota_bytes' => $quotaBytes,
			'worker_status' => $worker['status'],
			'global_queued' => $worker['queued_waiting'],
		]);
	}

	#[NoAdminRequired]
	public function library(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$items = $this->locationMapper->listLibraryForUser($user->getUID(), 200);
		return new JSONResponse(['items' => $items]);
	}

	#[NoAdminRequired]
	public function history(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$stateParam = $this->request->getParam('state');
		$state = (is_string($stateParam) && $stateParam !== '') ? $stateParam : null;
		$typeParam = $this->request->getParam('type');
		$type = (is_string($typeParam) && $typeParam !== '') ? $typeParam : null;
		$limit = min(200, max(1, (int)$this->request->getParam('limit', 50)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		if ($state === null) {
			$done = $this->jobMapper->listJobs('done', $limit, $offset, $user->getUID(), $type);
			$failed = $this->jobMapper->listJobs('failed', $limit, $offset, $user->getUID(), $type);
			$jobs = array_merge($done, $failed);
			usort($jobs, fn ($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
			$jobs = array_slice($jobs, 0, $limit);
		} else {
			$jobs = $this->jobMapper->listJobs($state, $limit, $offset, $user->getUID(), $type);
		}
		$serialized = array_map(fn ($j) => $this->jobDisplay->serialize($j), $jobs);
		$q = trim((string)$this->request->getParam('q', ''));
		if ($q !== '') {
			$qLower = strtolower($q);
			$serialized = array_values(array_filter(
				$serialized,
				fn ($j) => str_contains(strtolower((string)($j['filename'] ?? '')), $qLower)
					|| str_contains(strtolower((string)($j['type'] ?? '')), $qLower),
			));
		}
		return new JSONResponse([
			'jobs' => $serialized,
		]);
	}

	#[NoAdminRequired]
	public function logs(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
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
	public function preferences(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		return new JSONResponse(['preferences' => $this->preferences->getAll($user->getUID())]);
	}

	#[NoAdminRequired]
	public function savePreferences(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
		$allowed = [
			UserPreferencesService::KEY_AUTO_GENERATE,
			UserPreferencesService::KEY_NOTIFY_DONE,
			UserPreferencesService::KEY_DEFAULT_RENDITIONS,
			UserPreferencesService::KEY_CLIP_EXPORT_DIR,
		];
		$uid = $user->getUID();
		foreach ($allowed as $key) {
			if (!array_key_exists($key, $body)) {
				continue;
			}
			$val = is_bool($body[$key]) ? ($body[$key] ? 'yes' : 'no') : (string)$body[$key];
			$this->preferences->set($uid, $key, $val);
		}
		return new JSONResponse(['success' => true, 'preferences' => $this->preferences->getAll($uid)]);
	}

	#[NoAdminRequired]
	public function pinLibraryItem(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$fileid = (int)$this->request->getParam('fileid', 0);
		$pinned = filter_var($this->request->getParam('pinned', true), FILTER_VALIDATE_BOOLEAN);
		try {
			$this->userMedia->pin($user->getUID(), $fileid, $pinned);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function regenerateLibraryItem(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$fileid = (int)$this->request->getParam('fileid', 0);
		try {
			$this->permissions->assertCanGenerateHls($user);
			$jobId = $this->userMedia->regenerate($user->getUID(), $fileid);
			return new JSONResponse(['success' => true, 'jobId' => $jobId]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function deleteLibraryCache(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$fileid = (int)$this->request->getParam('fileid', 0);
		try {
			$this->userMedia->deleteCacheForUser($user->getUID(), $fileid);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function listPublicLinks(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$uid = $user->getUID();
		$links = $this->publicLinkMapper->listForUser($uid);
		$out = [];
		foreach ($links as $link) {
			$loc = $this->locationMapper->findByFileid($link->getFileid());
			$out[] = [
				'id' => $link->getId(),
				'fileid' => $link->getFileid(),
				'filename' => $loc !== null ? basename($loc->getPath()) : '',
				'url' => $this->publicLinks->publicUrl($link),
				'expires_at' => $link->getExpiresAt(),
				'view_count' => $link->getViewCount(),
				'created_at' => $link->getCreatedAt(),
			];
		}
		return new JSONResponse(['links' => $out]);
	}

	#[NoAdminRequired]
	public function createPublicLink(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		try {
			$this->permissions->assertCanCreatePublicLink($user);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 403);
		}
		$fileid = (int)$this->request->getParam('fileid', 0);
		$days = (int)$this->request->getParam('expires_days', 0);
		$expiresAt = $days > 0 ? time() + ($days * 86400) : null;
		try {
			$link = $this->publicLinks->create($user->getUID(), $fileid, $expiresAt);
			return new JSONResponse([
				'success' => true,
				'link' => [
					'id' => $link->getId(),
					'url' => $this->publicLinks->publicUrl($link),
					'expires_at' => $link->getExpiresAt(),
				],
			]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function revokePublicLink(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$id = (int)$this->request->getParam('id', 0);
		try {
			$this->publicLinks->revoke($user->getUID(), $id);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function exportLogs(): Response {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$entries = $this->logMapper->tailForUser($user->getUID(), 500);
		$lines = array_map(
			static fn ($e) => sprintf(
				"[%s] %s %s job=%s\n",
				date('c', $e->getCreatedAt()),
				$e->getLevel(),
				$e->getMessage(),
				(string)($e->getJobId() ?? ''),
			),
			$entries,
		);
		return new DataDownloadResponse(implode('', $lines), 'hyperviewer-logs.txt', 'text/plain');
	}

	#[NoAdminRequired]
	public function streamUrl(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$fileid = (int)$this->request->getParam('fileid', 0);
		try {
			$file = $this->mediaIndex->resolveFileForUser($user->getUID(), $fileid);
			$media = $this->mediaIndex->getMediaForFile($file, $user->getUID());
			if ($media === null) {
				return new JSONResponse(['error' => 'Not indexed'], 404);
			}
			$fp = $media->getFingerprint();
			$url = $this->urlGenerator->linkToRouteAbsolute(
				'hyperviewer.hls.serve',
				['fingerprint' => $fp, 'filename' => 'master.m3u8'],
			);
			return new JSONResponse(['url' => $url, 'fingerprint' => $fp]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function cancelMyQueued(): JSONResponse {
		$auth = $this->guardAppUser();
		if ($auth instanceof JSONResponse) {
			return $auth;
		}
		$user = $auth;
		$n = $this->jobMapper->cancelQueuedForUser($user->getUID());
		return new JSONResponse(['success' => true, 'cancelled' => $n]);
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

	private function readUserStorageFree(string $uid): ?int {
		try {
			$folder = $this->rootFolder->getUserFolder($uid);
			$free = $folder->getFreeSpace();
			return $free >= 0 ? (int)$free : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
