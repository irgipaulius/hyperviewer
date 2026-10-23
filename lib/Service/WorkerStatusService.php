<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\JobMapper;
use OCP\IAppConfig;

/**
 * Persists hyperviewer:worker liveness in app config (not controllable from the UI).
 */
class WorkerStatusService {
	public const HEARTBEAT_KEY = 'worker_heartbeat_at';
	public const PID_KEY = 'worker_pid';
	public const STARTED_KEY = 'worker_started_at';
	public const CONCURRENCY_KEY = 'worker_reported_concurrency';
	public const STALE_SECONDS = 30;

	public function __construct(
		private IAppConfig $appConfig,
		private ConfigService $config,
		private JobMapper $jobMapper,
		private FfmpegInfoService $ffmpegInfo,
	) {
	}

	public function registerWorker(int $concurrency): void {
		$now = time();
		$this->appConfig->setValueInt('hyperviewer', self::PID_KEY, getmypid());
		$this->appConfig->setValueInt('hyperviewer', self::STARTED_KEY, $now);
		$this->appConfig->setValueInt('hyperviewer', self::CONCURRENCY_KEY, $concurrency);
		$this->appConfig->setValueInt('hyperviewer', self::HEARTBEAT_KEY, $now);
	}

	public function heartbeat(): void {
		$this->appConfig->setValueInt('hyperviewer', self::HEARTBEAT_KEY, time());
		$this->appConfig->setValueInt('hyperviewer', self::PID_KEY, getmypid());
	}

	public function unregisterWorker(): void {
		$this->appConfig->setValueInt('hyperviewer', self::HEARTBEAT_KEY, 0);
		$this->appConfig->setValueInt('hyperviewer', self::PID_KEY, 0);
	}

	/** @return array<string, mixed> */
	public function getSnapshot(): array {
		$now = time();
		$heartbeatAt = $this->appConfig->getValueInt('hyperviewer', self::HEARTBEAT_KEY, 0);
		$pid = $this->appConfig->getValueInt('hyperviewer', self::PID_KEY, 0);
		$startedAt = $this->appConfig->getValueInt('hyperviewer', self::STARTED_KEY, 0);
		$reportedConcurrency = $this->appConfig->getValueInt('hyperviewer', self::CONCURRENCY_KEY, 0);
		$configuredConcurrency = $this->config->getInt(ConfigService::KEY_CONCURRENCY);
		$queued = $this->jobMapper->countByState('queued');

		$age = $heartbeatAt > 0 ? ($now - $heartbeatAt) : null;
		$status = 'stopped';
		if ($heartbeatAt > 0 && $age !== null && $age <= self::STALE_SECONDS) {
			$status = 'running';
		} elseif ($heartbeatAt > 0 && $age !== null && $age <= 300) {
			$status = 'stale';
		}

		$runningJobs = $this->jobMapper->countByState('running');
		if ($status === 'stopped' && $runningJobs > 0) {
			$status = 'stale';
		}

		return [
			'status' => $status,
			'pid' => $pid > 0 ? $pid : null,
			'heartbeat_at' => $heartbeatAt > 0 ? $heartbeatAt : null,
			'started_at' => $startedAt > 0 ? $startedAt : null,
			'uptime_seconds' => ($startedAt > 0 && $status === 'running') ? ($now - $startedAt) : null,
			'heartbeat_age_seconds' => $age,
			'queued_waiting' => $queued,
			'concurrency_configured' => $configuredConcurrency,
			'concurrency_reported' => $reportedConcurrency > 0 ? $reportedConcurrency : $configuredConcurrency,
			'ffmpeg_threads' => $this->config->getInt(ConfigService::KEY_THREADS),
			'queue_paused' => $this->config->getBool(ConfigService::KEY_QUEUE_PAUSED),
			'start_hint_service' => 'hyperviewer_worker',
			'start_hint_command' => 'HV_WORKER=1 occ hyperviewer:worker',
			'ffmpeg' => $this->ffmpegInfo->getVersionsForApi(),
			'disk_free_bytes' => $this->ffmpegInfo->diskFreeAtCachePath(),
			'hardware_encoders' => $this->ffmpegInfo->detectHardwareEncoders(),
		];
	}

	/** @return array{status: string, queued_waiting: int} */
	public function getPersonalHint(): array {
		$snap = $this->getSnapshot();
		return [
			'status' => (string)($snap['status'] ?? 'stopped'),
			'queued_waiting' => (int)($snap['queued_waiting'] ?? 0),
		];
	}
}
