<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\Media;
use OCA\HyperViewer\Db\WatchDir;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\VideoCollectionService;
use OCA\HyperViewer\Service\WatchScanService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

class WatchScanServiceTest extends TestCase {
	public function testSkipsReadyAndQueuedFiles(): void {
		$entries = [
			['fileid' => 1, 'filename' => 'ready.mp4', 'path' => '/v/ready.mp4'],
			['fileid' => 2, 'filename' => 'new.mp4', 'path' => '/v/new.mp4'],
			['fileid' => 3, 'filename' => 'queued.mp4', 'path' => '/v/queued.mp4'],
		];

		$video = $this->createMock(VideoCollectionService::class);
		$video->method('collectInFolder')->willReturn($entries);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturnSelf();

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$readyMedia = $this->createMock(Media::class);
		$readyMedia->method('getAutoDisabled')->willReturn(false);
		$readyMedia->method('getCacheState')->willReturn('ready');

		$mediaIndex = $this->createMock(MediaIndexService::class);
		$mediaIndex->method('resolveFileForUser')->willReturnCallback(
			function (string $uid, int $fileid) {
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn($fileid);
				return $file;
			},
		);
		$mediaIndex->method('getMediaForFile')->willReturnCallback(
			function (File $file) use ($readyMedia) {
				if ($file->getId() === 1) {
					return $readyMedia;
				}
				return null;
			},
		);

		$jobMapper = $this->createMock(JobMapper::class);
		$jobMapper->method('hasActivePipelineForFileid')->willReturnCallback(
			static fn (int $fid) => $fid === 3,
		);
		$jobMapper->method('hasActiveForMedia')->willReturn(false);

		$queue = $this->createMock(QueueService::class);
		$enqueued = [];
		$queue->method('enqueueFingerprint')->willReturnCallback(
			static function ($uid, $fid) use (&$enqueued) {
				$enqueued[] = $fid;
				return new Job();
			},
		);

		$watch = new WatchDir();
		$watch->setId(1);
		$watch->setResolutions(json_encode(['720p']));

		$watchMapper = $this->createMock(WatchDirMapper::class);
		$watchMapper->method('findAllForUser')->willReturn([$watch]);
		$watchMapper->expects(self::once())->method('update');

		$svc = new WatchScanService($root, $video, $mediaIndex, $queue, $jobMapper, $watchMapper);

		$job = new Job();
		$job->setUserId('paulius');
		$job->setPayload(json_encode(['path' => '/Pictures', 'watchId' => 1]));
		$svc->processScanJob($job);

		self::assertSame([2], $enqueued);
	}

	public function testSecondScanDoesNotEnqueueWhenPipelineActive(): void {
		$entries = [
			['fileid' => 2, 'filename' => 'new.mp4', 'path' => '/v/new.mp4'],
		];

		$video = $this->createMock(VideoCollectionService::class);
		$video->method('collectInFolder')->willReturn($entries);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturnSelf();

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$mediaIndex = $this->createMock(MediaIndexService::class);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(2);
		$mediaIndex->method('resolveFileForUser')->willReturn($file);
		$mediaIndex->method('getMediaForFile')->willReturn(null);

		$activeAfterFirst = false;
		$jobMapper = $this->createMock(JobMapper::class);
		$jobMapper->method('hasActivePipelineForFileid')->willReturnCallback(
			function () use (&$activeAfterFirst) {
				return $activeAfterFirst;
			},
		);
		$jobMapper->method('hasActiveForMedia')->willReturn(false);

		$queue = $this->createMock(QueueService::class);
		$enqueueCount = 0;
		$queue->method('enqueueFingerprint')->willReturnCallback(
			function () use (&$enqueueCount, &$activeAfterFirst) {
				$enqueueCount++;
				$activeAfterFirst = true;
				return new Job();
			},
		);

		$watch = new WatchDir();
		$watch->setId(1);
		$watch->setResolutions(json_encode(['720p']));

		$watchMapper = $this->createMock(WatchDirMapper::class);
		$watchMapper->method('findAllForUser')->willReturn([$watch]);

		$svc = new WatchScanService($root, $video, $mediaIndex, $queue, $jobMapper, $watchMapper);
		$job = new Job();
		$job->setUserId('paulius');
		$job->setPayload(json_encode(['path' => '/Pictures', 'watchId' => 1]));

		$svc->processScanJob($job);
		$svc->processScanJob($job);

		self::assertSame(1, $enqueueCount);
	}
}
