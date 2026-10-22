<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\QueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AdminController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private JobMapper $jobMapper,
		private MediaMapper $mediaMapper,
		private LogMapper $logMapper,
		private WatchDirMapper $watchDirMapper,
		private QueueService $queueService,
	) {
		parent::__construct($appName, $request);
	}

	#[AdminRequired]
	public function overview(): JSONResponse {
		$qbStats = [
			'queue_queued' => $this->jobMapper->countByState('queued'),
			'queue_running' => $this->jobMapper->countByState('running'),
			'queue_failed' => $this->jobMapper->countByState('failed'),
			'queue_paused' => $this->config->getBool(ConfigService::KEY_QUEUE_PAUSED),
		];
		return new JSONResponse($qbStats);
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
		$level = $this->request->getParam('level');
		$channel = $this->request->getParam('channel');
		$since = (int)$this->request->getParam('sinceId', 0);
		$entries = $this->logMapper->tail($level, $channel, 200, $since);
		return new JSONResponse(['entries' => array_map(static fn ($e) => [
			'id' => $e->getId(),
			'level' => $e->getLevel(),
			'channel' => $e->getChannel(),
			'message' => $e->getMessage(),
			'context' => $e->getContext(),
			'created_at' => $e->getCreatedAt(),
		], $entries)]);
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
