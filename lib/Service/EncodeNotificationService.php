<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCP\Notification\IManager as INotificationManager;

class EncodeNotificationService {
	public function __construct(
		private UserPreferencesService $userPreferences,
		private JobMapper $jobMapper,
		private INotificationManager $notificationManager,
	) {
	}

	public function onEncodeFinished(Job $job, bool $success, ?string $error): void {
		$uid = (string)$job->getUserId();
		if ($uid === '') {
			return;
		}
		$mode = $this->userPreferences->getHlsNotifyMode($uid);
		if ($mode === UserPreferencesService::HLS_NOTIFY_OFF) {
			return;
		}
		if ($mode === UserPreferencesService::HLS_NOTIFY_PER_FILE) {
			$this->send($uid, $job, $success ? 'encode_ready' : 'encode_failed', $success ? 'HLS cache is ready' : ($error ?? 'Encode failed'));
			return;
		}
		// summary mode — accumulate until the user's encode pipeline is idle
		if ($success) {
			$this->userPreferences->incrementPendingReady($uid);
		} else {
			$this->userPreferences->incrementPendingFailed($uid);
		}
		if (!$this->jobMapper->userHasActiveEncodePipeline($uid)) {
			$this->flushSummary($uid);
		}
	}

	private function flushSummary(string $uid): void {
		$ready = $this->userPreferences->takePendingReady($uid);
		$failed = $this->userPreferences->takePendingFailed($uid);
		if ($ready > 0) {
			$this->send($uid, null, 'encode_batch_ready', "HLS ready for {$ready} video(s)");
		}
		if ($failed > 0) {
			$this->send($uid, null, 'encode_batch_failed', "{$failed} video(s) failed to encode");
		}
	}

	private function send(string $uid, ?Job $job, string $subject, string $message): void {
		try {
			$n = $this->notificationManager->createNotification();
			$n->setApp('hyperviewer')
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('job', $job !== null ? (string)$job->getId() : 'batch')
				->setSubject($subject, ['job' => $job?->getId()])
				->setMessage($message);
			$this->notificationManager->notify($n);
		} catch (\Throwable) {
		}
	}
}
