<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\WorkerStatusService;
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
			],
			'cache' => array_merge($cache, [
				'cache_directory' => $cacheDir,
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
		return new JSONResponse(['items' => array_map(static fn ($w) => [
			'id' => $w->getId(),
			'user_id' => $w->getUserId(),
			'path' => $w->getPath(),
			'enabled' => $w->getEnabled(),
			'resolutions' => json_decode($w->getResolutions() ?? '[]', true),
			'last_scan_at' => $w->getLastScanAt(),
		], $all)]);
	}
}
