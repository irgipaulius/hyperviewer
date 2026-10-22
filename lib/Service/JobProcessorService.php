<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LogMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

class JobProcessorService {
	public function __construct(
		private JobMapper $jobMapper,
		private QueueService $queueService,
		private MediaIndexService $mediaIndex,
		private HlsEncodeService $hlsEncode,
		private FfmpegRunner $ffmpeg,
		private ConfigService $config,
		private IRootFolder $rootFolder,
		private INotificationManager $notificationManager,
		private LogMapper $logMapper,
	) {
	}

	public function process(Job $job): void {
		if ($job->getState() === 'cancelled') {
			return;
		}
		try {
			match ($job->getType()) {
				'fingerprint' => $this->processFingerprint($job),
				'encode', 'upgrade' => $this->processEncode($job),
				'clip' => $this->processClip($job),
				'scan' => $this->processScan($job),
				default => throw new \RuntimeException('Unknown job type'),
			};
			$this->queueService->markDone($job);
		} catch (\Throwable $e) {
			$this->logMapper->append('error', 'worker', $e->getMessage(), ['job' => $job->getId()], $job->getUserId(), $job->getFileid(), $job->getId());
			$this->queueService->markFailed($job, $e->getMessage());
			if ($job->getType() === 'encode' || $job->getType() === 'upgrade') {
				$this->notifyEncode($job, false, $e->getMessage());
			}
		}
	}

	private function processFingerprint(Job $job): void {
		$fileid = (int)$job->getFileid();
		$file = $this->mediaIndex->resolveFileForUser($job->getUserId(), $fileid);
		$media = $this->mediaIndex->indexOrRebind($file, $job->getUserId());
		$job->setMediaId($media->getId());
		$this->jobMapper->update($job);
		$payload = json_decode($job->getPayload() ?? '{}', true) ?: [];
		$autoEncode = (bool)($payload['autoEncode'] ?? false);
		if ($autoEncode && $media->getCacheState() === 'none' && !$media->getAutoDisabled()) {
			$resolutions = $payload['resolutions'] ?? $this->config->getDefaultRenditions();
			$this->queueService->enqueueEncode($job->getUserId(), $fileid, $media->getId(), $resolutions, true);
		}
	}

	private function processEncode(Job $job): void {
		$fileid = (int)$job->getFileid();
		$file = $this->mediaIndex->resolveFileForUser($job->getUserId(), $fileid);
		$media = $this->mediaIndex->indexOrRebind($file, $job->getUserId());
		$lastProgressWrite = 0;
		$this->hlsEncode->encodeJob(
			$job,
			$file,
			$media,
			fn (): bool => $this->jobMapper->findById($job->getId())->getState() === 'cancelled',
			function (int $progress) use ($job, &$lastProgressWrite): void {
				$now = time();
				if ($now - $lastProgressWrite >= 2) {
					$job->setProgress($progress);
					$this->jobMapper->update($job);
					$this->queueService->heartbeat($job);
					$lastProgressWrite = $now;
				}
			},
		);
		$this->notifyEncode($job, true, null);
	}

	private function processClip(Job $job): void {
		$payload = json_decode($job->getPayload() ?? '{}', true) ?: [];
		$file = $this->mediaIndex->resolveFileForUser($job->getUserId(), (int)$job->getFileid());
		$local = $file->getStorage()->getLocalFile($file->getInternalPath());
		if ($local === false) {
			throw new \RuntimeException('Clip export requires local storage');
		}
		$start = (float)($payload['start'] ?? 0);
		$duration = (float)($payload['duration'] ?? 0);
		$exportRel = (string)($payload['exportDir'] ?? '../exports');
		$clipName = (string)($payload['clipFilename'] ?? 'clip.mp4');
		$clipName = $this->sanitizeClipFilename($clipName, $file->getName());
		$userFolder = $this->rootFolder->getUserFolder($job->getUserId());
		$videoDir = dirname('/' . $file->getInternalPath());
		$exportDir = $this->normalizePath($videoDir . '/' . $exportRel);
		$this->createDirectoryRecursive($userFolder, ltrim($exportDir, '/'));
		$exportFolder = $userFolder->get(ltrim($exportDir, '/'));
		if (!$exportFolder instanceof Folder) {
			throw new \RuntimeException('Export folder unavailable');
		}
		$tmp = tempnam(sys_get_temp_dir(), 'hv_clip_');
		$ext = pathinfo($clipName, PATHINFO_EXTENSION) ?: pathinfo($file->getName(), PATHINFO_EXTENSION);
		$tmpFile = $tmp . '.' . $ext;
		rename($tmp, $tmpFile);
		$args = [
			'-y', '-ss', (string)$start, '-i', $local, '-t', (string)$duration,
			'-c', 'copy', '-avoid_negative_ts', 'make_zero', $tmpFile,
		];
		$code = $this->ffmpeg->run($args);
		if ($code !== 0) {
			@unlink($tmpFile);
			throw new \RuntimeException('Clip ffmpeg failed');
		}
		$handle = fopen($tmpFile, 'rb');
		if ($handle === false) {
			throw new \RuntimeException('Failed to read clip temp file');
		}
		try {
			if ($exportFolder->nodeExists($clipName)) {
				$clipName = $this->suffixConflict($clipName);
			}
			$exportFolder->newFile($clipName, $handle);
		} finally {
			fclose($handle);
			@unlink($tmpFile);
		}
	}

	private function processScan(Job $job): void {
		$payload = json_decode($job->getPayload() ?? '{}', true) ?: [];
		$path = (string)($payload['path'] ?? '/');
		$userFolder = $this->rootFolder->getUserFolder($job->getUserId());
		if (!$userFolder->nodeExists(ltrim($path, '/'))) {
			return;
		}
		$folder = $userFolder->get(ltrim($path, '/'));
		if (!$folder instanceof Folder) {
			return;
		}
		$this->scanFolder($folder, $job->getUserId());
	}

	private function scanFolder(Folder $folder, string $userId): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->scanFolder($node, $userId);
				continue;
			}
			if (!$node instanceof \OCP\Files\File) {
				continue;
			}
			$mime = $node->getMimeType();
			if (!in_array($mime, ['video/mp4', 'video/quicktime'], true)) {
				continue;
			}
			if ($node->getSize() <= 0) {
				continue;
			}
			$this->queueService->enqueueFingerprint($userId, $node->getId());
		}
	}

	private function sanitizeClipFilename(string $name, string $sourceName): string {
		$name = basename(str_replace('\\', '/', $name));
		if ($name === '' || str_contains($name, '..') || preg_match('/[\x00-\x1f]/', $name)) {
			throw new \RuntimeException('Invalid clip filename');
		}
		$srcExt = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($ext === '') {
			$name .= '.' . $srcExt;
		} elseif ($ext !== $srcExt) {
			throw new \RuntimeException('Clip extension must match source');
		}
		return $name;
	}

	private function suffixConflict(string $name): string {
		$base = pathinfo($name, PATHINFO_FILENAME);
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		return $base . ' (2)' . ($ext !== '' ? '.' . $ext : '');
	}

	private function normalizePath(string $path): string {
		$path = preg_replace('#/+#', '/', $path);
		$parts = explode('/', $path);
		$normalized = [];
		foreach ($parts as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				array_pop($normalized);
			} else {
				$normalized[] = $part;
			}
		}
		return '/' . implode('/', $normalized);
	}

	private function createDirectoryRecursive(Folder $userFolder, string $path): void {
		$parts = explode('/', trim($path, '/'));
		$currentPath = '';
		foreach ($parts as $part) {
			$currentPath = $currentPath === '' ? $part : $currentPath . '/' . $part;
			if (!$userFolder->nodeExists($currentPath)) {
				$userFolder->newFolder($currentPath);
			}
		}
	}

	private function notifyEncode(Job $job, bool $success, ?string $error): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('hyperviewer')
			->setUser($job->getUserId())
			->setDateTime(new \DateTime())
			->setObject('encode', (string)$job->getId())
			->setSubject($success ? 'encode_ready' : 'encode_failed', ['fileid' => $job->getFileid()])
			->setMessage($success ? 'ready' : ($error ?? 'failed'));
		$this->notificationManager->notify($n);
	}
}
