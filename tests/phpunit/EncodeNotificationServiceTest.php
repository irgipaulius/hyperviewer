<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Service\EncodeNotificationService;
use OCA\HyperViewer\Service\UserPreferencesService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class EncodeNotificationServiceTest extends TestCase {
	public function testSummaryModeSendsOneNotificationWhenPipelineDrains(): void {
		$prefs = $this->createMock(UserPreferencesService::class);
		$prefs->method('getHlsNotifyMode')->willReturn(UserPreferencesService::HLS_NOTIFY_SUMMARY);
		$prefs->expects(self::exactly(37))->method('incrementPendingReady');
		$prefs->method('takePendingReady')->willReturn(37);
		$prefs->method('takePendingFailed')->willReturn(0);

		$calls = 0;
		$jobs = $this->createMock(JobMapper::class);
		$jobs->method('userHasActiveEncodePipeline')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				return $calls < 37;
			},
		);

		$notifier = $this->createMock(INotificationManager::class);
		$notifier->expects(self::once())->method('notify');

		$svc = new EncodeNotificationService($prefs, $jobs, $notifier);
		$job = new Job();
		$job->setUserId('paulius');
		$job->setId(1);
		for ($i = 0; $i < 37; $i++) {
			$svc->onEncodeFinished($job, true, null);
		}
	}

	public function testOffModeSendsNoNotifications(): void {
		$prefs = $this->createMock(UserPreferencesService::class);
		$prefs->method('getHlsNotifyMode')->willReturn(UserPreferencesService::HLS_NOTIFY_OFF);

		$notifier = $this->createMock(INotificationManager::class);
		$notifier->expects(self::never())->method('notify');

		$svc = new EncodeNotificationService($prefs, $this->createMock(JobMapper::class), $notifier);
		$job = new Job();
		$job->setUserId('paulius');
		$svc->onEncodeFinished($job, true, null);
	}

	public function testSummaryGroupsFailures(): void {
		$prefs = $this->createMock(UserPreferencesService::class);
		$prefs->method('getHlsNotifyMode')->willReturn(UserPreferencesService::HLS_NOTIFY_SUMMARY);
		$prefs->expects(self::exactly(3))->method('incrementPendingFailed');
		$prefs->method('takePendingReady')->willReturn(0);
		$prefs->method('takePendingFailed')->willReturn(3);

		$calls = 0;
		$jobs = $this->createMock(JobMapper::class);
		$jobs->method('userHasActiveEncodePipeline')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				return $calls < 3;
			},
		);

		$notifier = $this->createMock(INotificationManager::class);
		$notifier->expects(self::once())->method('notify')->willReturnCallback(
			function (INotification $n) {
				self::assertSame('encode_batch_failed', $n->getSubject());
				return $n;
			},
		);

		$svc = new EncodeNotificationService($prefs, $jobs, $notifier);
		$job = new Job();
		$job->setUserId('paulius');
		for ($i = 0; $i < 3; $i++) {
			$svc->onEncodeFinished($job, false, 'fail');
		}
	}
}
