<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\CacheIntegrityService;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\WorkerStatusService;
use OCA\HyperViewer\Db\LocationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AdminController extends Controller {
	private static ?int $lastDiskScanAt = null;
	private static ?int $lastDiskScanBytes = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private JobMapper $jobMapper,
		private MediaMapper $mediaMapper,
		private LogMapper $logMapper,
		private WatchDirMapper $watchDirMapper,
		private WorkerStatusService $workerStatus,
		private CacheIntegrityService $cacheIntegrity,
		private QueueService $queueService,
		private LocationMapper $locationMapper,
	) {
		parent::__construct($appName, $request);
	}

	#[AdminRequired]
	public function overview(): JSONResponse {
		return new JSONResponse($this->buildDashboardPayload());
	}

	#[AdminRequired]
	public function status(): JSONResponse {
		return new JSONResponse($this->buildDashboardPayload());
	}

	/** @return array<string, mixed> */
	private function buildDashboardPayload(): array {
		$since = time() - 86400;
		$cache = $this->mediaMapper->getCacheSummary();
		$cacheDir = $this->config->getString(ConfigService::KEY_CACHE_DIR);
		$diskBytes = $this->getDiskBytesCached($cacheDir);
		$avgEncode = $this->jobMapper->avgDoneEncodeDurationSince($since);

		return [
			'worker' => $this->workerStatus->getSnapshot(),
			'queue' => [
				'queued' => $this->jobMapper->countByState('queued'),
				'running' => $this->jobMapper->countByState('running'),
				'failed' => $this->jobMapper->countByState('failed'),
				'done_24h' => $this->jobMapper->countByStateSince('done', $since),
				'cancelled' => $this->jobMapper->countByState('cancelled'),
				'paused' => $this->config->getBool(ConfigService::KEY_QUEUE_PAUSED),
				'by_type' => $this->jobMapper->countQueuedGroupedByType(),
				'users_with_queued' => $this->jobMapper->countDistinctUsersWithQueued(),
				'max_queued_per_user' => $this->jobMapper->maxQueuedDepthPerUser(),
				'avg_encode_seconds_24h' => $avgEncode,
				'last_failed_category' => $this->jobMapper->latestFailedErrorCategory(),
				'queued_depth_histogram' => $this->jobMapper->queuedDepthHistogram(),
			],
			'cache' => array_merge($cache, [
				'cache_bytes_disk_estimate' => $diskBytes,
				'failed_jobs' => $this->jobMapper->countByState('failed'),
			]),
			// Legacy keys for older cockpit fragments
			'queue_queued' => $this->jobMapper->countByState('queued'),
			'queue_running' => $this->jobMapper->countByState('running'),
			'queue_failed' => $this->jobMapper->countByState('failed'),
			'queue_paused' => $this->config->getBool(ConfigService::KEY_QUEUE_PAUSED),
		];
	}

	private function getDiskBytesCached(string $cacheDir): ?int {
		$now = time();
		if (self::$lastDiskScanAt !== null && ($now - self::$lastDiskScanAt) < 60) {
			return self::$lastDiskScanBytes;
		}
		self::$lastDiskScanBytes = $this->estimateDiskBytes($cacheDir);
		self::$lastDiskScanAt = $now;
		return self::$lastDiskScanBytes;
	}

	private function estimateDiskBytes(string $cacheDir): ?int {
		if ($cacheDir === '' || !is_dir($cacheDir)) {
			return null;
		}
		$size = 0;
		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
			);
			foreach ($it as $file) {
				if ($file->isFile()) {
					$size += $file->getSize();
				}
			}
		} catch (\Throwable) {
			return null;
		}
		return $size;
	}

	#[AdminRequired]
	public function getSettings(): JSONResponse {
		return new JSONResponse(['settings' => $this->config->getAllWithSources()]);
	}

	#[AdminRequired]
	public function setSetting(): JSONResponse {
		$key = (string)$this->request->getParam('key', '');
		$value = (string)$this->request->getParam('value', '');
		if ($key === '') {
			return new JSONResponse(['error' => 'key required'], 400);
		}
		$this->config->setOverride($key, $value);
		return new JSONResponse(['success' => true]);
	}

	#[AdminRequired]
	public function resetSetting(): JSONResponse {
		$key = (string)$this->request->getParam('key', '');
		$this->config->resetOverride($key);
		return new JSONResponse(['success' => true]);
	}

	#[AdminRequired]
	public function logs(): JSONResponse {
		$entries = $this->logMapper->tailSystemForAdmin(200);
		return new JSONResponse(['entries' => array_map(static fn ($e) => [
			'id' => $e->getId(),
			'level' => $e->getLevel(),
			'channel' => $e->getChannel(),
			'message' => LogMapper::redactMessage((string)$e->getMessage()),
			'created_at' => $e->getCreatedAt(),
		], $entries)]);
	}

	#[AdminRequired]
	public function pauseQueue(): JSONResponse {
		$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
		$paused = filter_var($body['paused'] ?? $this->request->getParam('paused', false), FILTER_VALIDATE_BOOLEAN);
		$this->config->setOverride(ConfigService::KEY_QUEUE_PAUSED, $paused ? 'true' : 'false');
		return new JSONResponse(['success' => true, 'paused' => $paused]);
	}

	#[AdminRequired]
	public function watchDirs(): JSONResponse {
		$all = $this->watchDirMapper->findAllEnabled();
		return new JSONResponse(['enabled_count' => count($all)]);
	}

	#[AdminRequired]
	public function diagnosticReport(): JSONResponse {
		return new JSONResponse($this->buildDashboardPayload());
	}

	#[AdminRequired]
	public function cancelFailed(): JSONResponse {
		$n = $this->jobMapper->cancelAllFailed();
		return new JSONResponse(['success' => true, 'cancelled' => $n]);
	}

	#[AdminRequired]
	public function purgeLogs(): JSONResponse {
		$days = max(1, $this->config->getInt(ConfigService::KEY_LOG_RETENTION_DAYS));
		$purged = $this->logMapper->pruneOlderThan($days * 86400);
		return new JSONResponse(['success' => true, 'purged' => $purged]);
	}

	#[AdminRequired]
	public function drainQueue(): JSONResponse {
		$n = $this->jobMapper->cancelAllQueued();
		return new JSONResponse(['success' => true, 'cancelled' => $n]);
	}

	#[AdminRequired]
	public function verifyCache(): JSONResponse {
		return new JSONResponse($this->cacheIntegrity->verifyReadyCaches(1000));
	}

	#[AdminRequired]
	public function purgeHistory(): JSONResponse {
		$days = max(1, $this->config->getInt(ConfigService::KEY_LOG_RETENTION_DAYS));
		$purged = $this->jobMapper->purgeFinishedOlderThan($days * 86400);
		return new JSONResponse(['success' => true, 'purged' => $purged]);
	}

	#[AdminRequired]
	public function rebuildIndex(): JSONResponse {
		$scanJobs = 0;
		$adoptJobs = 0;
		$userIds = [];
		foreach ($this->watchDirMapper->findAllEnabled() as $wd) {
			$userIds[$wd->getUserId()] = true;
			try {
				$this->queueService->enqueueScan($wd->getUserId(), $wd->getPath(), null, true);
				$scanJobs++;
			} catch (\Throwable) {
			}
		}
		foreach ($this->locationMapper->listDistinctUserIds() as $uid) {
			$userIds[$uid] = true;
		}
		foreach (array_keys($userIds) as $uid) {
			try {
				$this->queueService->enqueueAdopt($uid);
				$adoptJobs++;
			} catch (\Throwable) {
			}
		}
		return new JSONResponse([
			'success' => true,
			'scan_jobs' => $scanJobs,
			'adopt_jobs' => $adoptJobs,
		]);
	}
}
